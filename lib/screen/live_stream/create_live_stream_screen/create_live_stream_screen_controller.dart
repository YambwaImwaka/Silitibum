import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:livekit_client/livekit_client.dart'
    show CameraPosition, LocalVideoTrack, LocalVideoTrackExt, VideoTrackRenderer, VideoViewFit;
import 'package:permission_handler/permission_handler.dart';
import 'package:shortzz/common/controller/base_controller.dart';
import 'package:shortzz/common/functions/auth_gate.dart';
import 'package:shortzz/common/manager/logger.dart';
import 'package:shortzz/common/manager/session_manager.dart';
import 'package:shortzz/common/service/api/livestream_service.dart';
import 'package:shortzz/common/service/live/live_video.dart';
import 'package:shortzz/common/widget/confirmation_dialog.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/model/general/settings_model.dart';
import 'package:shortzz/model/livestream/livestream.dart';
import 'package:shortzz/model/user_model/user_model.dart';
import 'package:shortzz/screen/live_stream/livestream_screen/host/livestream_host_screen.dart';

class CreateLiveStreamScreenController extends BaseController {
  RxBool isRestricted = false.obs;
  bool isFrontCamera = true;

  Rx<User?> get myUser => SessionManager.instance.getUser().obs;

  Setting? get _setting => SessionManager.instance.getSettings();
  Rx<Widget?> localView = Rx(null);
  TextEditingController titleController = TextEditingController();

  /// The camera previewed here is the one the live publishes. It is lent to
  /// the host screen and stays this screen's to stop: if the live cannot
  /// start, the host screen closes and this preview is still running for
  /// another try. A finished live replaces this screen, which stops it.
  LocalVideoTrack? _camera;

  @override
  void onInit() {
    super.onInit();
    // Defensive second gate: broadcasting requires an account, and the flow
    // below asks for camera + mic before it would ever discover that. Bail
    // out before requesting any permission rather than dead-ending later.
    if (!AuthGate.isLoggedIn) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        Get.back();
        AuthGate.check();
      });
      return;
    }
    initCamera();
  }

  @override
  void onClose() {
    super.onClose();
    stopPreview();
  }

  Future<bool> requestPermission() async {
    Loggers.info("requestPermission...");
    try {
      PermissionStatus microphoneStatus = await Permission.microphone.request();
      if (microphoneStatus != PermissionStatus.granted) {
        Loggers.error('Error: Microphone permission not granted!!!');
        return false;
      }
    } on Exception catch (error) {
      Loggers.error("[ERROR], request microphone permission exception, $error");
      return false;
    }

    try {
      PermissionStatus cameraStatus = await Permission.camera.request();
      if (cameraStatus != PermissionStatus.granted) {
        Loggers.error('[Error]: Camera permission not granted!!!');
        return false;
      }
    } on Exception catch (error) {
      Loggers.error("[ERROR], request camera permission exception, $error");
      return false;
    }

    return true;
  }

  // Needs nothing set up first. Under Zego this preview silently failed on
  // the Profile and Snaps ➕ routes, which never created the engine.
  void initCamera() async {
    bool isPermissionGranted = await requestPermission();
    if (isPermissionGranted) {
      await initializeCameraPreview();
    } else {
      Get.bottomSheet(ConfirmationSheet(
          title: LKey.cameraMicrophonePermissionTitle.tr,
          description: LKey.cameraMicrophonePermissionDescription.tr,
          onTap: openAppSettings));
    }
  }

  Future<void> initializeCameraPreview() async {
    try {
      showLoader();
      final camera = await LiveVideo.createCamera(front: isFrontCamera);
      if (isClosed) {
        await camera.stop();
        return;
      }
      _camera = camera;
      localView.value = VideoTrackRenderer(camera, fit: VideoViewFit.cover);
    } catch (e, stackTrace) {
      Loggers.error('Failed to initialize camera preview: $e\n$stackTrace');
    } finally {
      stopLoader();
    }
  }

  void toggleCamera() {
    final LocalVideoTrack? camera = _camera;
    if (camera == null) return;
    isFrontCamera = !isFrontCamera;
    camera
        .setCameraPosition(
            isFrontCamera ? CameraPosition.front : CameraPosition.back)
        .catchError((e) => Loggers.error('Camera switch failed: $e'));
  }

  void onCloseTap() {
    Get.back();
  }

  Future<void> stopPreview() async {
    final LocalVideoTrack? camera = _camera;
    _camera = null;
    localView.value = null;
    try {
      await camera?.stop();
    } catch (e) {
      Loggers.error('Stopping the camera preview failed: $e');
    }
  }

  Future<void> onStartLive() async {
    if ((myUser.value?.followerCount ?? 0) <
        (_setting?.minFollowersForLive ?? 0)) {
      showSnackBar(LKey.minFollowersNeededToGoLive
          .trParams({'count': '${_setting?.minFollowersForLive}'}));
      return;
    }

    if (titleController.text.trim().isEmpty) {
      return showSnackBar(LKey.enterLiveStreamTitle.tr);
    }

    User? user = myUser.value;
    if (user == null) {
      Loggers.error('User Not found. Cannot start live stream.');
      return;
    }
    int userId = user.id ?? -1;

    if (userId == -1) {
      Loggers.error('Wrong User ID is $userId');
      return;
    }

    final LocalVideoTrack? camera = _camera;
    if (camera == null) {
      showSnackBar(LKey.cameraMicrophonePermissionTitle.tr);
      return;
    }

    Loggers.info('Starting live stream (userId $userId)...');
    showLoader();

    try {
      // The backend creates the room (unique room_id), the host participant
      // row, and ends any stale previous stream of this host.
      final result = await LivestreamService.instance.createLivestream(
          description: titleController.text.trim(),
          type: LivestreamType.livestream.value,
          isRestrictToJoin: isRestricted.value ? 1 : 0);
      stopLoader();

      final Livestream? livestream = result.livestream;
      if (result.status != true || livestream == null) {
        showSnackBar(result.message);
        return;
      }

      Loggers.success('Livestream started successfully!');
      Get.to(() => LivestreamHostScreen(
          camera: camera, livestream: livestream, isHost: true));
    } catch (e, stackTrace) {
      stopLoader(); // Ensure loader stops in all cases
      Loggers.error('Failed to start live stream: $e');
      Loggers.error('StackTrace: $stackTrace');
      showSnackBar(LKey.somethingWentWrong.tr);
    }
  }
}

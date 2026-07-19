import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:permission_handler/permission_handler.dart';
import 'package:shortzz/common/controller/base_controller.dart';
import 'package:shortzz/common/manager/logger.dart';
import 'package:shortzz/common/manager/session_manager.dart';
import 'package:shortzz/common/service/api/livestream_service.dart';
import 'package:shortzz/common/widget/confirmation_dialog.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/model/general/settings_model.dart';
import 'package:shortzz/model/livestream/livestream.dart';
import 'package:shortzz/model/user_model/user_model.dart';
import 'package:shortzz/screen/live_stream/livestream_screen/host/livestream_host_screen.dart';
import 'package:zego_express_engine/zego_express_engine.dart';

class CreateLiveStreamScreenController extends BaseController {
  RxBool isRestricted = false.obs;
  bool isFrontCamera = true;
  ZegoExpressEngine zegoEngine = ZegoExpressEngine.instance;

  Rx<User?> get myUser => SessionManager.instance.getUser().obs;

  Setting? get _setting => SessionManager.instance.getSettings();
  Rx<Widget?> localView = Rx(null);
  RxInt localViewID = RxInt(-1);
  TextEditingController titleController = TextEditingController();

  @override
  void onInit() {
    super.onInit();
    initZegoEngine();
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

  void initZegoEngine() async {
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
      // Enable the front camera and un-mute audio streams
      await zegoEngine.enableCamera(true);
      await zegoEngine.mutePublishStreamAudio(false);
      zegoEngine.muteMicrophone(false);

      // Use the front camera for the main publishing channel
      zegoEngine.useFrontCamera(true, channel: ZegoPublishChannel.Main);

      // Create a canvas view for local video preview
      await zegoEngine.createCanvasView((viewID) async {
        localViewID.value = viewID;
        Loggers.info('LOCAL VIEW ID : $localViewID');

        // Set up the preview canvas with aspect fill mode
        ZegoCanvas previewCanvas =
            ZegoCanvas(viewID, viewMode: ZegoViewMode.AspectFill);
        zegoEngine.startPreview(canvas: previewCanvas);
      }).then((canvasViewWidget) {
        // Assign the preview widget to a reactive variable
        localView.value = canvasViewWidget;
      });
    } catch (e, stackTrace) {
      // Log any errors during the preview setup
      Loggers.error('Failed to initialize camera preview: $e\n$stackTrace');
    } finally {
      stopLoader();
    }
  }

  void toggleCamera() {
    isFrontCamera = !isFrontCamera;
    zegoEngine.useFrontCamera(isFrontCamera, channel: ZegoPublishChannel.Main);
  }

  void onCloseTap() {
    Get.back();
    stopPreview();
  }

  Future<void> stopPreview() async {
    zegoEngine.stopPreview();
    if (localViewID.value != -1) {
      await zegoEngine.destroyCanvasView(localViewID.value);
      localViewID.value = -1;
      localView.value = null;
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

    if (localView.value == null) {
      showSnackBar('Local View not found');
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
          isRestrictToJoin: isRestricted.value ? 1 : 0,
          hostViewId: localViewID.value);
      stopLoader();

      final Livestream? livestream = result.livestream;
      if (result.status != true || livestream == null) {
        showSnackBar(result.message);
        return;
      }
      livestream.hostViewID = localViewID.value;

      Loggers.success('Livestream started successfully!');
      Widget? hostPreview = localView.value;
      Get.to(() => LivestreamHostScreen(
          hostPreview: hostPreview, livestream: livestream, isHost: true));
    } catch (e, stackTrace) {
      stopLoader(); // Ensure loader stops in all cases
      Loggers.error('Failed to start live stream: $e');
      Loggers.error('StackTrace: $stackTrace');
      showSnackBar(LKey.somethingWentWrong.tr);
    }
  }
}

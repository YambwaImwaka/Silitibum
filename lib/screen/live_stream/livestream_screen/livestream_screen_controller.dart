import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:just_audio/just_audio.dart';
import 'package:permission_handler/permission_handler.dart';
import 'package:shortzz/common/controller/ads_controller.dart';
import 'package:shortzz/common/controller/app_user_cache_controller.dart';
import 'package:shortzz/common/controller/base_controller.dart';
import 'package:shortzz/common/manager/firebase_notification_manager.dart';
import 'package:shortzz/common/manager/haptic_manager.dart';
import 'package:shortzz/common/manager/logger.dart';
import 'package:shortzz/common/manager/session_manager.dart';
import 'package:shortzz/common/service/api/livestream_service.dart';
import 'package:shortzz/common/service/api/notification_service.dart';
import 'package:shortzz/common/service/realtime/realtime_service.dart';
import 'package:shortzz/common/widget/confirmation_dialog.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/model/general/settings_model.dart';
import 'package:shortzz/model/livestream/app_user.dart';
import 'package:shortzz/model/livestream/livestream.dart';
import 'package:shortzz/model/livestream/livestream_comment.dart';
import 'package:shortzz/model/livestream/livestream_user_state.dart';
import 'package:shortzz/model/user_model/user_model.dart';
import 'package:shortzz/screen/gift_sheet/send_gift_sheet.dart';
import 'package:shortzz/screen/gift_sheet/send_gift_sheet_controller.dart';
import 'package:shortzz/screen/live_stream/live_stream_end_screen/live_stream_end_screen.dart';
import 'package:shortzz/screen/live_stream/live_stream_end_screen/widget/livestream_summary.dart';
import 'package:shortzz/screen/live_stream/livestream_screen/audience/widget/live_stream_join_sheet.dart';
import 'package:shortzz/screen/live_stream/livestream_screen/host/widget/live_stream_host_top_view.dart';
import 'package:shortzz/screen/report_sheet/report_sheet.dart';
import 'package:shortzz/utilities/app_res.dart';
import 'package:shortzz/utilities/asset_res.dart';
import 'package:video_player/video_player.dart';
import 'package:wakelock_plus/wakelock_plus.dart';
import 'package:zego_express_engine/zego_express_engine.dart';

/// Livestream room over the MySQL backend: REST for state changes, presence
/// channel events for live updates, polling fallback while the WebSocket is
/// down. Media transport is Zego, untouched by the Firebase removal.
class LivestreamScreenController extends BaseController {
  ZegoExpressEngine zegoEngine = ZegoExpressEngine.instance;

  final cacheController = Get.find<AppUserCacheController>();
  final adsController = Get.find<AdsController>();

  Timer? timer;
  Timer? minViewerTimeoutTimer;
  Function? onLikeTap;

  Setting? get setting => SessionManager.instance.getSettings();

  int get minViewersThreshold => setting?.liveMinViewers ?? 0;

  int get timeoutMinutes => setting?.liveTimeout ?? 0;

  int get myUserId => SessionManager.instance.getUserID();

  RxBool isPlayerMute = false.obs;
  RxBool isMinViewerTimeout = false.obs;
  RxBool isTextEmpty = true.obs;
  bool isJoinSheetOpen = false;
  bool isFrontCamera = true;
  bool isHost;

  TextEditingController textCommentController = TextEditingController();

  Widget? hostPreview;

  LivestreamScreenController(this.liveData, this.isHost, {this.hostPreview});

  int totalBattleSecond = 0;

  RxInt remainingBattleSeconds = 0.obs;
  RxBool isViewVisible = true.obs;

  List<LivestreamUserState> memberList = <LivestreamUserState>[];

  List<Gift> get gifts => setting?.gifts ?? [];
  RxList<LivestreamUserState> requestList = <LivestreamUserState>[].obs;
  RxList<LivestreamUserState> audienceList = <LivestreamUserState>[].obs;
  RxList<LivestreamUserState> invitedList = <LivestreamUserState>[].obs;
  RxList<LivestreamUserState> coHostList = <LivestreamUserState>[].obs;
  RxList<LivestreamUserState> audienceMemberList = <LivestreamUserState>[].obs;
  RxList<StreamView> streamViews = <StreamView>[].obs;
  RxList<LivestreamComment> comments = <LivestreamComment>[].obs;
  RxList<LivestreamUserState> liveUsersStates = <LivestreamUserState>[].obs;

  Rx<AppUser?> selectedGiftUser = Rx(null);
  Rx<VideoPlayerController?> videoPlayerController = Rx(null);

  Rx<User?> get myUser => SessionManager.instance.getUser().obs;
  Rx<Livestream> liveData;

  AudioPlayer countdownPlayer = AudioPlayer();
  AudioPlayer battleStartPlayer = AudioPlayer();
  AudioPlayer winAudioPlayer = AudioPlayer();

  List<User> usersList = [];

  String get roomId => liveData.value.roomID ?? '';

  StreamSubscription<RealtimeEvent>? _realtimeSub;
  Timer? _pollTimer;
  Timer? _likeFlushTimer;
  String? _presenceChannelName;
  int _lastCommentId = 0;
  int _pendingLikes = 0;
  int _knownLikeCount = 0;

  @override
  void onInit() {
    super.onInit();

    if (liveData.value.isDummyLive == 1) {
      initVideoPlayer();
    } else {
      totalBattleSecond =
          Duration(minutes: liveData.value.battleDuration).inSeconds;
      remainingBattleSeconds.value = totalBattleSecond;
      _knownLikeCount = liveData.value.likeCount ?? 0;
      zegoEngine.setAudioDeviceMode(ZegoAudioDeviceMode.General);
      loginRoom();
      startListenEvent();
      initAudioPlayer();
      _startRealtime();
      if (isHost) {
        _fetchFullState();
      }
    }

    WakelockPlus.enable();
    FirebaseNotificationManager.instance
        .unsubscribeToTopic(topic: myUserId.toString());
  }

  @override
  void onClose() {
    super.onClose();
    WakelockPlus.disable();
    timer?.cancel();
    minViewerTimeoutTimer?.cancel();
    videoPlayerController.value?.dispose();
    _realtimeSub?.cancel();
    _pollTimer?.cancel();
    _likeFlushTimer?.cancel();
    _flushPendingLikes();
    if (_presenceChannelName != null) {
      RealtimeService.instance.unsubscribe(_presenceChannelName!);
    }
    countdownPlayer.dispose();
    winAudioPlayer.dispose();
    stopListenEvent();
    logoutRoom();
  }

  Future<void> initVideoPlayer() async {
    final url = liveData.value.dummyUserLink ?? '';
    if (url.isEmpty) return;

    // Dispose old controller if exists to avoid memory leak
    await videoPlayerController.value?.dispose();

    final controller = VideoPlayerController.networkUrl(Uri.parse(url));
    isPlayerMute.value = false;

    try {
      await controller.initialize();
      controller
        ..setLooping(true)
        ..play();

      videoPlayerController.value = controller;
      videoPlayerController.value?.setLooping(true);
    } on PlatformException catch (e) {
      showSnackBar(e.message);
      Loggers.error(e);
    }
  }

  void initAudioPlayer() {
    countdownPlayer.setAsset(AssetRes.endCountdown);
    battleStartPlayer.setAsset(AssetRes.battleStart);
    winAudioPlayer.setAsset(AssetRes.winSound);
  }

  // ---------------------------------------------------------------------
  // REALTIME + POLLING (replaces the Firestore listeners)
  // ---------------------------------------------------------------------

  void _startRealtime() {
    if (roomId.isEmpty) return;
    RealtimeService.instance
        .subscribePresence('livestream.$roomId', RealtimeService.livestreamEvents);
    _presenceChannelName = 'presence-livestream.$roomId';
    _realtimeSub = RealtimeService.instance.events.listen(_onRealtimeEvent);
    _pollTimer = Timer.periodic(const Duration(seconds: 4), (_) {
      if (!RealtimeService.instance.isConnected) _pollState();
    });
    _likeFlushTimer = Timer.periodic(const Duration(seconds: 4), (_) {
      _flushPendingLikes();
    });
  }

  void _onRealtimeEvent(RealtimeEvent event) {
    if (event.channel != _presenceChannelName) return;
    switch (event.name) {
      case 'livestream.updated':
      case 'battle.updated':
        if (event.data['livestream'] is Map) {
          _applyStream(Livestream.fromJson(
              (event.data['livestream'] as Map).cast<String, dynamic>()));
        }
        break;
      case 'user_state.updated':
        if (event.data['user_state'] is Map) {
          final json =
              (event.data['user_state'] as Map).cast<String, dynamic>();
          final state = LivestreamUserState.fromJson(json);
          if (state.user != null) cacheController.addAppUser(state.user);
          _applyUserState(state);
        }
        break;
      case 'comment.sent':
        if (event.data['comment'] is Map) {
          final json = (event.data['comment'] as Map).cast<String, dynamic>();
          if (json['sender_user'] is Map) {
            cacheController.addAppUser(AppUser.fromJson(
                (json['sender_user'] as Map).cast<String, dynamic>()));
          }
          _appendComment(LivestreamComment.fromJson(json));
        }
        break;
      case 'livestream.ended':
        // The Zego stream-delete callback drives the exit UX; this event is
        // just a log marker (and keeps polling from spinning).
        Loggers.info('livestream.ended received for room $roomId');
        break;
    }
  }

  Future<void> _fetchFullState() async {
    try {
      final res = await LivestreamService.instance
          .fetchStreamState(roomId: roomId, afterCommentId: null);
      _applyStateResult(res, replaceComments: true);
    } catch (e) {
      Loggers.error('fetchStreamState failed: $e');
    }
  }

  Future<void> _pollState() async {
    if (roomId.isEmpty) return;
    try {
      final res = await LivestreamService.instance
          .fetchStreamState(roomId: roomId, afterCommentId: _lastCommentId);
      if (res.status != true && res.message == 'livestream_ended') {
        _pollTimer?.cancel();
        return;
      }
      _applyStateResult(res, replaceComments: false, replaceUsers: true);
    } catch (e) {
      Loggers.error('livestream poll failed: $e');
    }
  }

  void _applyStateResult(StreamStateResult res,
      {bool replaceComments = false, bool replaceUsers = true}) {
    if (res.status != true) return;
    if (res.livestream != null) _applyStream(res.livestream!);
    if (replaceUsers && res.users.isNotEmpty) _applyUserStates(res.users);
    if (replaceComments) {
      comments.clear();
      _lastCommentId = 0;
    }
    for (final comment in res.comments) {
      _appendComment(comment);
    }
  }

  /// Mirrors the old Firestore livestream-doc listener body.
  void _applyStream(Livestream stream) {
    if (stream.battleType == BattleType.initiate) {
      timer?.cancel();
      remainingBattleSeconds.value =
          Duration(minutes: stream.battleDuration).inSeconds;
      countdownPlayer.pause();
    }

    if (stream.battleType == BattleType.waiting) {
      totalBattleSecond = Duration(minutes: stream.battleDuration).inSeconds;
    }

    liveData.value = stream;

    // Trigger like animation if changed
    final newLikeCount = stream.likeCount ?? 0;
    if (_knownLikeCount != newLikeCount) {
      onLikeTap?.call();
      _knownLikeCount = newLikeCount;
    }
  }

  void _applyUserStates(List<LivestreamUserState> states) {
    for (final state in states) {
      _applyUserState(state, recompute: false);
    }
    // Drop members no longer present in the authoritative snapshot.
    final ids = states.map((e) => e.userId).toSet();
    liveUsersStates.removeWhere((element) => !ids.contains(element.userId));
    _recomputeUserLists();
  }

  void _applyUserState(LivestreamUserState state, {bool recompute = true}) {
    final oldState = liveUsersStates
        .firstWhereOrNull((element) => element.userId == state.userId);

    if (oldState == null) {
      _showJoinStreamSheet(state);
      liveUsersStates.add(state);
    } else {
      updateStateAction(oldState, state);
      final index =
          liveUsersStates.indexWhere((u) => u.userId == state.userId);
      if (index != -1) {
        liveUsersStates[index] = state;
      } else {
        liveUsersStates.add(state);
      }
    }
    if (recompute) _recomputeUserLists();
  }

  void _recomputeUserLists() {
    requestList.value = liveUsersStates
        .where((element) => element.type == LivestreamUserType.requested)
        .toList();
    audienceList.value = liveUsersStates
        .where((element) =>
            element.type != LivestreamUserType.host &&
            element.type != LivestreamUserType.left)
        .toList();
    invitedList.value = liveUsersStates
        .where((element) => element.type == LivestreamUserType.invited)
        .toList();
    coHostList.value = liveUsersStates
        .where((element) => element.type == LivestreamUserType.coHost)
        .toList();
    audienceMemberList.value = liveUsersStates
        .where((element) =>
            element.type != LivestreamUserType.left &&
            element.userId != myUserId)
        .toList();
  }

  void _appendComment(LivestreamComment comment) {
    if ((comment.id ?? 0) > _lastCommentId) {
      _lastCommentId = comment.id ?? 0;
    }
    cacheController.fetchUserIfNeeded(comment.senderId ?? -1);
    if (comment.commentType == LivestreamCommentType.request && !isHost) {
      return;
    }
    if (comments.any((element) => element.id == comment.id)) return;
    comment.gift ??=
        gifts.firstWhereOrNull((gift) => gift.id == comment.giftId);
    comments.add(comment);
    comments.sort((a, b) => (b.id ?? 0).compareTo(a.id ?? 0));
  }

  // ---------------------------------------------------------------------
  // ZEGO ROOM (unchanged mechanics)
  // ---------------------------------------------------------------------

  Future<void> logoutRoom() async {
    if (isHost) {
      _endStreamOnServer();
    } else if (roomId.isNotEmpty) {
      () async {
        try {
          await LivestreamService.instance.leaveLivestream(roomId: roomId);
        } catch (e) {
          Loggers.error('leaveLivestream failed: $e');
        }
      }();
    }
    stopPreview();
    stopPublish();
    zegoEngine.logoutRoom(roomId);
  }

  Future<ZegoRoomLoginResult> loginRoom() async {
    final user = ZegoUser('$myUserId', myUser.value?.username ?? '');

    final roomConfig = ZegoRoomConfig.defaultConfig()
      ..isUserStatusNotify = true;

    try {
      final result =
          await zegoEngine.loginRoom(roomId, user, config: roomConfig);

      if (result.errorCode != 0) {
        if (result.errorCode == 1001005) {
          showSnackBar(
              'Please check AppSign is correct or not on ZEGO manage console');
        } else {
          showSnackBar('loginRoom failed: ${result.errorCode}');
        }
        Loggers.error('Login Error : ${result.errorCode}');
        return result;
      }

      if (isHost) {
        startHostPublish();
        return result;
      }

      // Audience: register with the backend and pull the full room snapshot
      // (participants + last comments) in one round trip.
      try {
        final res =
            await LivestreamService.instance.joinLivestream(roomId: roomId);
        if (res.status == true) {
          _applyStateResult(res, replaceComments: true);
        } else if (res.message == 'livestream_ended') {
          showSnackBar(LKey.livestreamHasEnded.tr);
          Get.back();
        }
      } catch (e) {
        Loggers.error('joinLivestream failed: $e');
      }
      return result;
    } catch (e) {
      Loggers.error('Error in loginRoom: $e');
      showSnackBar('Something went wrong while joining the room.');
      rethrow;
    }
  }

  void startListenEvent() async {
    // Callback for updates on the status of other users in the room.
    ZegoExpressEngine.onRoomUserUpdate =
        (roomID, updateType, List<ZegoUser> userList) {
      if (userList.length > 1) {
        ZegoExpressEngine.instance.setAudioRouteToSpeaker(true);
      }
      if (isHost && updateType == ZegoUpdateType.Delete) {
        // Watching counts are server-maintained (join/leave endpoints); the
        // host only cleans up co-host slots when someone drops off Zego.
        Livestream stream = liveData.value;
        for (var element in userList) {
          int coHostId = int.tryParse(element.userID) ?? -1;
          bool isCoHostExist = stream.coHostIds?.contains(coHostId) ?? false;
          if (isCoHostExist) {
            updateUserStateToFirestore(coHostId,
                type: LivestreamUserType.audience,
                audioStatus: VideoAudioStatus.on,
                videoStatus: VideoAudioStatus.on);
          }
        }
      }
      Loggers.info(
          'onRoomUserUpdate: roomID: $roomID, updateType: ${updateType.name}, userList: ${userList.map((e) => e.userID)}');
    };
    // Callback for updates on the status of the streams in the room.
    ZegoExpressEngine.onRoomStreamUpdate =
        (roomID, updateType, List<ZegoStream> streamList, extendedData) async {
      String priorityId = liveData.value.hostId.toString();

      streamList.sort((a, b) {
        if (a.streamID == priorityId) return -1; // a goes first
        if (b.streamID == priorityId) return 1; // b goes first
        return a.streamID.compareTo(b.streamID); // regular sorting
      });
      Loggers.info(
          'onRoomStreamUpdate: roomID: $roomID, updateType: $updateType, streamList: ${streamList.map((e) => e.streamID)}, extendedData: $extendedData');
      switch (updateType) {
        case ZegoUpdateType.Add:
          for (final stream in streamList) {
            startPlayStream(stream.streamID);
          }
          break;
        case ZegoUpdateType.Delete:
          for (final stream in streamList) {
            if (liveData.value.roomID == stream.streamID) {
              if (Get.isBottomSheetOpen == false) {
                Get.back();
              }
              for (var element in liveUsersStates) {
                if (element.type == LivestreamUserType.coHost) {
                  streamEnded();
                }
              }
              // Empty LiveData
              logoutRoom();
              stopListenEvent();
              liveData.value = Livestream();
            }
            streamViews
                .removeWhere((element) => element.streamId == stream.streamID);
            stopPlayStream(stream.streamID);
          }
          break;
      }
    };
    // Callback for updates on the current user's room connection status.
    ZegoExpressEngine.onRoomStateUpdate =
        (roomID, state, errorCode, extendedData) {
      Loggers.info(
          'onRoomStateUpdate: roomID: $roomID, state: ${state.name}, errorCode: $errorCode, extendedData: $extendedData');
    };

    // Callback for updates on the current user's stream publishing changes.
    ZegoExpressEngine.onPublisherStateUpdate =
        (streamID, state, errorCode, extendedData) {
      switch (state) {
        case ZegoPublisherState.NoPublish:
          streamViews.removeWhere((element) => element.streamId == streamID);
        case ZegoPublisherState.PublishRequesting:
        case ZegoPublisherState.Publishing:
      }
      debugPrint(
          'onPublisherStateUpdate: streamID: $streamID, state: ${state.name}, errorCode: $errorCode, extendedData: $extendedData');
    };
  }

  void stopListenEvent() {
    ZegoExpressEngine.onRoomUserUpdate = null;
    ZegoExpressEngine.onRoomStreamUpdate = null;
    ZegoExpressEngine.onRoomStateUpdate = null;
    ZegoExpressEngine.onPublisherStateUpdate = null;
  }

  Future<void> startHostPublish() async {
    if (roomId.isEmpty) {
      return Loggers.error('No ID FOUND');
    }
    String streamID = roomId;
    if (hostPreview == null) {
      _endStreamOnServer();
      Get.back();
      return;
    }
    streamViews.add(StreamView(
        streamID, liveData.value.hostViewID ?? -1, hostPreview!, false));
    await zegoEngine.enableCamera(true);
    await zegoEngine.mutePublishStreamAudio(false); // Ensure audio is not muted
    startMinViewerTimeoutCheck(); //  Check time to Min. Viewers Required to continue live
    pushNotificationToFollowers(liveData.value);
    return zegoEngine.startPublishingStream(streamID);
  }

  Future<void> stopPublish() async {
    return zegoEngine.stopPublishingStream();
  }

  Future<void> startPlayStream(String streamID) async {
    Loggers.info('Starting to play stream: $streamID');
    int streamViewId = -1;
    try {
      await zegoEngine.createCanvasView((viewID) async {
        Loggers.info('Created remote view with ID: $viewID');
        streamViewId = viewID;
        ZegoCanvas canvas =
            ZegoCanvas(viewID, viewMode: ZegoViewMode.AspectFill);
        ZegoPlayerConfig config = ZegoPlayerConfig.defaultConfig();
        config.resourceMode = ZegoStreamResourceMode.Default; // live streaming(cdn)

        try {
          await zegoEngine.startPlayingStream(streamID, canvas: canvas, config: config);
        } catch (e) {
          print(e);
        }
      }).then((canvasViewWidget) {
        if (canvasViewWidget != null) {
          streamViews
              .add(StreamView(streamID, streamViewId, canvasViewWidget, false));
        }
        Loggers.success('Stream playback started successfully for: $streamID');
      });
    } catch (e, stackTrace) {
      Loggers.error('Failed to start playing stream: $e');
      Loggers.error('StackTrace: $stackTrace');
    }
  }

  Future<void> stopPlayStream(String streamID) async {
    Loggers.info('Stopping playback for stream: $streamID');

    try {
      zegoEngine.stopPlayingStream(streamID);
      Loggers.success('Stopped playing stream: $streamID');

      StreamView? stream = streamViews
          .firstWhereOrNull((element) => element.streamId == streamID);

      if (stream?.streamViewId != null) {
        Loggers.info('Destroying remote view with ID: ${stream?.streamViewId}');
        await zegoEngine.destroyCanvasView(stream!.streamViewId);
        Loggers.success('Remote view destroyed successfully.');
      }
    } catch (e, stackTrace) {
      Loggers.error('Failed to stop playing stream: $e');
      Loggers.error('StackTrace: $stackTrace');
    }
  }

  Future<void> stopPreview({int? viewId}) async {
    int id = viewId ?? -1;
    zegoEngine.stopPreview();
    if (id != -1) {
      await zegoEngine.destroyCanvasView(id);
    }
  }

  // ---------------------------------------------------------------------
  // SERVER STATE CHANGES (replaces Firestore doc updates)
  // ---------------------------------------------------------------------

  /// Battle/type changes go to the server; watching counts and co-host id
  /// lists are server-maintained side effects of join/leave/updateUserState,
  /// so those legacy parameters are accepted but ignored.
  Future<void> updateLiveStreamData({
    BattleType? battleType,
    LivestreamType? type,
    int? battleCreatedAt,
    int? battleDuration,
    int watchingCount = 0,
    dynamic coHostId,
  }) async {
    if (battleType == null && type == null && battleDuration == null) return;
    try {
      await LivestreamService.instance.updateBattleState(
          roomId: roomId,
          battleType: (battleType ?? liveData.value.battleType)?.value ??
              BattleType.initiate.value,
          type: type?.value,
          battleDuration: battleDuration);
    } catch (e) {
      Loggers.error('updateBattleState failed: $e');
    }
  }

  void handleRequestResponse({
    required AppUser? user,
    required bool isRefused,
    LivestreamComment? comment,
  }) {
    final userId = user?.userId;
    if (userId == null) return;

    // Update user state based on refusal
    updateUserStateToFirestore(userId,
        type: isRefused
            ? LivestreamUserType.audience
            : LivestreamUserType.coHost);

    // The request line disappears from the host's feed locally (history on
    // the server keeps it, harmless).
    final commentToDelete = comment ??
        comments.firstWhereOrNull((element) =>
            element.senderId == userId &&
            element.commentType == LivestreamCommentType.request);
    if (commentToDelete != null) {
      comments.removeWhere((element) => element.id == commentToDelete.id);
    }
  }

  Future<void> _endStreamOnServer() async {
    if (roomId.isEmpty) return;
    try {
      await LivestreamService.instance.endLivestream(roomId: roomId);
      Loggers.success('livestream ended on server for room $roomId');
    } catch (e) {
      Loggers.error('endLivestream failed: $e');
    }
  }

  void toggleCamera() {
    isFrontCamera = !isFrontCamera;
    zegoEngine.useFrontCamera(isFrontCamera, channel: ZegoPublishChannel.Main);
  }

  void toggleFlipCamera() {
    isFrontCamera = !isFrontCamera;
    zegoEngine.useFrontCamera(isFrontCamera, channel: ZegoPublishChannel.Main);
  }

  void toggleMic(LivestreamUserState? state) async {
    if (state?.audioStatus == VideoAudioStatus.offByHost) {
      return showSnackBar(LKey.theHostHasTurnedOffYourAudio);
    }

    bool isAudioOn = state?.audioStatus == VideoAudioStatus.on;

    if (isAudioOn) {
      updateUserStateToFirestore(myUserId,
          audioStatus: VideoAudioStatus.offByMe);
      zegoEngine.muteMicrophone(true);
    } else {
      updateUserStateToFirestore(myUserId, audioStatus: VideoAudioStatus.on);
      zegoEngine.muteMicrophone(false);
    }
  }

  void toggleVideo(LivestreamUserState? state) async {
    if (state?.videoStatus == VideoAudioStatus.offByHost) {
      return showSnackBar(LKey.theHostHasTurnedOffYourVideo.tr);
    }
    bool isVideoOn = state?.videoStatus == VideoAudioStatus.on;
    if (isVideoOn) {
      updateUserStateToFirestore(myUserId,
          videoStatus: VideoAudioStatus.offByMe);
      await zegoEngine.enableCamera(false);
    } else {
      updateUserStateToFirestore(myUserId, videoStatus: VideoAudioStatus.on);
      await zegoEngine.enableCamera(true);
    }
  }

  void toggleStreamAudio(int? streamId) {
    StreamView? view = streamViews
        .firstWhereOrNull((element) => int.parse(element.streamId) == streamId);

    zegoEngine.mutePlayStreamAudio(
        '$streamId', (view?.isMuted ?? false) ? false : true);
    view?.isMuted = view.isMuted ? false : true;
    if (view != null) {
      streamViews[streamViews.indexWhere(
          (element) => int.parse(element.streamId) == streamId)] = view;
      streamViews.refresh();
    }
  }

  /// Hearts: instant local animation, batched server flush (one addLikes call
  /// per few seconds instead of one write per tap).
  void onLikeButtonTap() async {
    HapticManager.shared.light();
    _pendingLikes++;
    _knownLikeCount++;
    liveData.update((val) => val?.likeCount = (val.likeCount ?? 0) + 1);
    onLikeTap?.call();
  }

  void _flushPendingLikes() {
    if (_pendingLikes <= 0 || roomId.isEmpty) return;
    final count = _pendingLikes;
    _pendingLikes = 0;
    () async {
      try {
        await LivestreamService.instance.addLikes(roomId: roomId, count: count);
      } catch (e) {
        Loggers.error('addLikes failed: $e');
      }
    }();
  }

  void onTextCommentSend() {
    String comment = textCommentController.text.trim();
    textCommentController.clear();
    isTextEmpty.value = true;
    if (comment.isEmpty) return;
    _sendComment(type: LivestreamCommentType.text, comment: comment);
  }

  void onGiftTap(GiftType type,
      {BattleView battleViewType = BattleView.red,
      List<AppUser> users = const []}) {
    users.removeWhere((element) => element.userId == myUserId);
    if (liveData.value.type == LivestreamType.battle &&
        liveData.value.battleType == BattleType.end) {
      return showSnackBar(LKey.battleEndedGiftNotSent.tr);
    }
    GiftManager.openGiftSheet(
        onCompletion: (giftManager) async {
          Gift gift = giftManager.gift;
          AppUser? user = giftManager.streamUser;
          if (user?.userId == null || gift.id == null) return;

          // The livestream endpoint is the single payer: it moves the coins,
          // records participant earnings and broadcasts the gift comment.
          try {
            final res = await LivestreamService.instance.sendStreamGift(
                roomId: roomId,
                receiverUserId: user!.userId!,
                giftId: gift.id!.toInt());
            if (res.status == true) {
              final me = SessionManager.instance.getUser();
              me?.removeCoinFromWallet(gift.coinPrice ?? 0);
              SessionManager.instance.setUser(me);
            } else {
              showSnackBar(res.message);
            }
          } catch (e) {
            Loggers.error('sendStreamGift failed: $e');
            showSnackBar(LKey.somethingWentWrong.tr);
          }
        },
        giftType: type,
        battleViewType: battleViewType,
        streamUsers: users);
  }

  Future<void> _sendComment(
      {required LivestreamCommentType type,
      String? comment,
      int? receiverId}) async {
    try {
      await LivestreamService.instance.sendComment(
          roomId: roomId,
          comment: comment,
          commentType: type.value,
          receiverId: receiverId);
    } catch (e) {
      Loggers.error('sendComment failed: $e');
    }
  }

  void onVideoRequestSend(Livestream liveData) {
    LivestreamUserState? state = liveUsersStates
        .firstWhereOrNull((element) => element.userId == myUserId);
    switch (state?.type) {
      case null:
        break;
      case LivestreamUserType.audience:
        updateUserStateToFirestore(myUserId,
            type: LivestreamUserType.requested);
        _sendComment(
            type: LivestreamCommentType.request, receiverId: liveData.hostId);
        showSnackBar(LKey.requestJoinToHost.tr);
        break;
      case LivestreamUserType.requested:
        showSnackBar(LKey.joinRequestSentDescription.tr);
        break;
      case LivestreamUserType.host:
      case LivestreamUserType.coHost:
      case LivestreamUserType.invited:
      case LivestreamUserType.left:
        break;
    }
  }

  /// Legacy name kept for the widgets: state changes go to the backend now.
  /// Coin parameters are server-managed (gifts / battle banking) and follow
  /// registration has its own endpoint — they are ignored here.
  Future<void> updateUserStateToFirestore(
    int? userId, {
    LivestreamUserType? type,
    VideoAudioStatus? audioStatus,
    VideoAudioStatus? videoStatus,
    int? battleCoin,
    int? liveCoin,
    bool? isFollow,
    int? joinTime,
    int? currentBattleCoin,
  }) async {
    if (userId == null) {
      Loggers.error('updateUserState: userId is null');
      return;
    }

    if (isFollow == true) {
      try {
        await LivestreamService.instance
            .registerFollowGained(roomId: roomId, userId: userId);
      } catch (e) {
        Loggers.error('registerFollowGained failed: $e');
      }
    }

    if (type == null && audioStatus == null && videoStatus == null) return;
    try {
      await LivestreamService.instance.updateUserState(
          roomId: roomId,
          userId: userId,
          type: type?.value,
          audioStatus: audioStatus?.value,
          videoStatus: videoStatus?.value);
      Loggers.success('User state updated for userId: $userId');
    } catch (e) {
      Loggers.error('Failed to update user state: $e');
    }
  }

  void onInvite(AppUser? user, {bool isInvited = false}) {
    updateUserStateToFirestore(user?.userId,
        type: isInvited
            ? LivestreamUserType.audience
            : LivestreamUserType.invited);
  }

  void _showJoinStreamSheet(LivestreamUserState state) {
    if (state.userId == myUserId && state.type == LivestreamUserType.invited) {
      AppUser? hostUser = liveData.value.getHostUser(cacheController.users);
      isJoinSheetOpen = true;
      Get.bottomSheet(
              LiveStreamJoinSheet(
                  hostUser: hostUser,
                  myUser: myUser.value,
                  onJoined: () async {
                    LivestreamUserState? userState =
                        liveUsersStates.firstWhereOrNull(
                            (element) => element.userId == myUserId);
                    if (userState?.type == LivestreamUserType.invited) {
                      updateUserStateToFirestore(myUserId,
                          type: LivestreamUserType.coHost);
                    } else {
                      showSnackBar(LKey.joinCancelledDescription.tr);
                    }
                  },
                  onCancel: () {
                    updateUserStateToFirestore(myUserId,
                        type: LivestreamUserType.audience);
                  }),
              isScrollControlled: true,
              enableDrag: false,
              isDismissible: false)
          .then(
        (value) {
          isJoinSheetOpen = false;
        },
      );
    }
  }

  void publishCoHostStream(int streamId) async {
    bool isPermissionGranted = await requestPermission();
    if (isPermissionGranted) {
      int canvasViewID = -1;

      // ✅ Enable camera and microphone
      await zegoEngine.enableCamera(true);
      await zegoEngine.mutePublishStreamAudio(false);
      zegoEngine.muteMicrophone(false);

      // ✅ Create preview canvas and start preview
      await zegoEngine.createCanvasView((viewID) async {
        canvasViewID = viewID;
        ZegoCanvas previewCanvas =
            ZegoCanvas(canvasViewID, viewMode: ZegoViewMode.AspectFill);
        zegoEngine.startPreview(canvas: previewCanvas);
      }).then((canvasViewWidget) {
        if (canvasViewWidget != null) {
          streamViews.add(
            StreamView('$streamId', canvasViewID, canvasViewWidget, false),
          );
        }
      });

      // ✅ Publish the stream
      await zegoEngine.startPublishingStream('$streamId');

      // ✅ Force audio output to speaker (after stream starts)
      Future.delayed(const Duration(milliseconds: 300), () {
        zegoEngine.setAudioRouteToSpeaker(true);
      });

      // Server side already flipped this user to CO-HOST (that's what
      // triggered this publish) — just announce it in the feed.
      _sendComment(type: LivestreamCommentType.joinedCoHost);
    } else {
      Get.bottomSheet(ConfirmationSheet(
        title: LKey.cameraMicrophonePermissionTitle.tr,
        description: LKey.cameraMicrophonePermissionDescription.tr,
        onTap: openAppSettings,
      ));
    }
  }

  void closeCoHostStream(int? streamId) {
    StreamView? view = streamViews
        .firstWhereOrNull((element) => element.streamId == '$streamId');
    if (view != null) {
      stopPreview(viewId: view.streamViewId);
      stopPublish();
      // The joined-co-host line disappears locally.
      comments.removeWhere((element) =>
          element.senderId == myUserId &&
          element.commentType == LivestreamCommentType.joinedCoHost);
      updateUserStateToFirestore(streamId,
          type: LivestreamUserType.audience,
          audioStatus: VideoAudioStatus.offByMe,
          videoStatus: VideoAudioStatus.offByMe);
      streamViews.removeWhere((element) => element.streamId == '$streamId');
      stopPlayStream(streamId.toString());
      streamEnded();
    }
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
    }

    try {
      PermissionStatus cameraStatus = await Permission.camera.request();
      if (cameraStatus != PermissionStatus.granted) {
        Loggers.error('Error: Camera permission not granted!!!');
        return false;
      }
    } on Exception catch (error) {
      Loggers.error("[ERROR], request camera permission exception, $error");
    }

    return true;
  }

  void coHostVideoToggle(LivestreamUserState state) {
    if (state.videoStatus == VideoAudioStatus.offByMe) {
      return showSnackBar(LKey.theCoHostHasTurnedOffTheirVideo);
    }

    updateUserStateToFirestore(state.userId,
        videoStatus: state.videoStatus == VideoAudioStatus.on
            ? VideoAudioStatus.offByHost
            : VideoAudioStatus.on);
  }

  void coHostAudioToggle(LivestreamUserState state) {
    if (state.audioStatus == VideoAudioStatus.offByMe) {
      return showSnackBar(LKey.theCoHostHasTurnedOffTheirAudio);
    }

    updateUserStateToFirestore(state.userId,
        audioStatus: state.audioStatus == VideoAudioStatus.on
            ? VideoAudioStatus.offByHost
            : VideoAudioStatus.on);
  }

  void updateStateAction(
      LivestreamUserState? oldState, LivestreamUserState newState) {
    if (newState.userId == myUserId) {
      Loggers.info('Updating state for userId: ${newState.toJson()}');
      if (newState.type == LivestreamUserType.coHost &&
          oldState?.type != LivestreamUserType.coHost) {
        publishCoHostStream(myUserId);
      }

      if (newState.type == LivestreamUserType.audience &&
          oldState?.type == LivestreamUserType.invited &&
          isJoinSheetOpen) {
        Get.back();
      }

      if (newState.type == LivestreamUserType.invited &&
          oldState?.type == LivestreamUserType.audience) {
        _showJoinStreamSheet(newState);
      }
      if (oldState?.type == LivestreamUserType.coHost &&
          newState.type == LivestreamUserType.audience) {
        closeCoHostStream(newState.userId);
      }
    }
  }

  void coHostDelete(LivestreamUserState state) {
    if (state.type == LivestreamUserType.coHost) {
      updateUserStateToFirestore(state.userId,
          type: LivestreamUserType.audience);
    }
  }

  void reportUser(int? userId) {
    Get.bottomSheet(ReportSheet(reportType: ReportType.user, id: userId),
        isScrollControlled: true);
  }

  void _timerStart(VoidCallback callBack) {
    timer = Timer.periodic(
      const Duration(milliseconds: 100),
      (t) {
        callBack.call();
      },
    );
  }

  void onStopButtonTap() {
    bool isBattleOn = liveData.value.type == LivestreamType.battle;
    String title =
        !isBattleOn ? LKey.endStreamTitle.tr : LKey.stopBattleTitle.tr;
    String description =
        !isBattleOn ? LKey.endStreamMessage.tr : LKey.stopBattleDescription.tr;

    Get.bottomSheet(
        StopLiveStreamSheet(
            onTap: () {
              if (isBattleOn) {
                updateLiveStreamData(
                    battleType: BattleType.initiate,
                    type: LivestreamType.livestream);
                startMinViewerTimeoutCheck();
              } else {
                hostEndStream();
              }
            },
            title: title,
            description: description,
            positiveText: LKey.stop.tr),
        isScrollControlled: true);
  }

  void hostEndStream() {
    streamEnded();
    logoutRoom();
  }

  void streamEnded() {
    LivestreamUserState? userState = liveUsersStates
        .firstWhereOrNull((element) => element.userId == myUserId);
    AppUser? user = cacheController.users
        .firstWhereOrNull((element) => element.userId == myUserId);
    userState?.user = user;
    int viewers = liveUsersStates.length;
    if (isHost) {
      Get.back();
      Get.off(() => LiveStreamEndScreen(
          userState: userState, isHost: isHost, viewers: viewers));
    } else {
      if (userState?.type == LivestreamUserType.coHost) {
        Get.bottomSheet(
                LiveStreamSummary(
                    userState: userState, isHost: isHost, viewers: viewers),
                isScrollControlled: true)
            .then((value) {
          updateUserStateToFirestore(myUserId,
              type: LivestreamUserType.audience);
          if (roomId.isEmpty) {
            Get.back();
          }
        });
      }
    }
  }

  togglePlayerAudioToggle() {
    videoPlayerController.value?.setVolume(isPlayerMute.value ? 1 : 0);
    isPlayerMute.value = !isPlayerMute.value;
  }

  void toggleView() {
    isViewVisible.value = !isViewVisible.value;
  }

  void startBattle() {
    updateLiveStreamData(
      battleType: BattleType.waiting,
      battleDuration: AppRes.battleDurationInMinutes,
    );
  }

  void battleRunning() {
    Livestream stream = liveData.value;
    // Battle Start Timer Logic — counts down from the server-stamped start.
    final startTime =
        DateTime.fromMillisecondsSinceEpoch(stream.battleCreatedAt ?? 0);
    final endTime = startTime
        .add(Duration(seconds: totalBattleSecond + AppRes.battleStartInSecond));

    Loggers.success('Battle Timer Started');

    _timerStart(() {
      final remaining = endTime.difference(DateTime.now()).inSeconds;
      remainingBattleSeconds.value = remaining.clamp(0, totalBattleSecond);

      if (remainingBattleSeconds.value <= 10) {
        if (!countdownPlayer.playing) {
          countdownPlayer
              .seek(Duration(seconds: 10 - remainingBattleSeconds.value));
          countdownPlayer.play();
        }
      }

      if (remainingBattleSeconds.value <= 0) {
        winAudioPlayer.seek(const Duration(seconds: 0));
        winAudioPlayer.play();
        timer?.cancel();
        // Only the host is authoritative for ending the round (the server
        // banks the coins exactly once).
        if (isHost) {
          updateLiveStreamData(battleType: BattleType.end);
        }
      }
    });
  }

  void startMinViewerTimeoutCheck() {
    if (minViewerTimeoutTimer?.isActive ?? false) return;
    Loggers.info(
        'Check Min. Viewers Required to continue live $timeoutMinutes Minutes');
    minViewerTimeoutTimer =
        Timer.periodic(Duration(minutes: timeoutMinutes), (_) {
      minViewerTimeoutTimer?.cancel();
      if ((liveData.value.watchingCount ?? 0) <= minViewersThreshold) {
        isMinViewerTimeout.value = true;
        Loggers.info('Close Stream Because of Min. Viewers');
      }
    });
  }

  void onCloseAudienceBtn() {
    HapticManager.shared.light();
    Get.bottomSheet(ConfirmationSheet(
        title: LKey.exitLiveStreamTitle.tr,
        description: LKey.exitLiveStreamDescription.tr,
        onTap: () async {
          adsController.showInterstitialAdIfAvailable();
          if (liveData.value.coHostIds?.contains(myUserId) ?? false) {
            closeCoHostStream(myUserId);
          }
          logoutRoom();
        }));
  }

  void pushNotificationToFollowers(Livestream liveData) {
    AppUser? hostUser = liveData.getHostUser([]);
    NotificationService.instance.pushNotification(
        type: NotificationType.liveStream,
        title: LKey.liveStreamNotificationTitle
            .trParams({'name': hostUser?.username ?? ''}),
        body: LKey.liveStreamNotificationBody.tr,
        deviceType: 1,
        topic: '${liveData.hostId}_ios',
        data: liveData.toJson());
    NotificationService.instance.pushNotification(
        type: NotificationType.liveStream,
        title: LKey.liveStreamNotificationTitle
            .trParams({'name': hostUser?.username ?? ''}),
        body: LKey.liveStreamNotificationBody.tr,
        deviceType: 0,
        topic: '${liveData.hostId}_android',
        data: liveData.toJson());
  }
}

class StreamView {
  String streamId;
  int streamViewId;
  Widget streamView;
  bool isMuted;

  StreamView(this.streamId, this.streamViewId, this.streamView, this.isMuted);
}

import 'dart:async';
import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:image_picker/image_picker.dart';
import 'package:just_audio/just_audio.dart';
import 'package:livekit_client/livekit_client.dart'
    show
        DisconnectReason,
        EventsListener,
        LocalVideoTrack,
        ParticipantDisconnectedEvent,
        ParticipantPermissionsUpdatedEvent,
        RemoteParticipant,
        Room,
        RoomDisconnectedEvent,
        RoomEvent,
        TrackSubscribedEvent,
        TrackUnsubscribedEvent,
        VideoTrack,
        VideoTrackRenderer,
        VideoViewFit;
// Prefixed: Flutter has its own ConnectionState.
import 'package:livekit_client/livekit_client.dart' as lk show ConnectionState;
import 'package:path_provider/path_provider.dart';
import 'package:permission_handler/permission_handler.dart';
import 'package:shortzz/common/controller/ads_controller.dart';
import 'package:shortzz/common/controller/app_user_cache_controller.dart';
import 'package:shortzz/common/controller/base_controller.dart';
import 'package:shortzz/common/functions/auth_gate.dart';
import 'package:shortzz/common/manager/firebase_notification_manager.dart';
import 'package:shortzz/common/manager/haptic_manager.dart';
import 'package:shortzz/common/manager/live_recording_manager.dart';
import 'package:shortzz/common/manager/logger.dart';
import 'package:shortzz/common/manager/session_manager.dart';
import 'package:shortzz/common/service/api/livestream_service.dart';
import 'package:shortzz/common/service/api/notification_service.dart';
import 'package:shortzz/common/service/live/live_video.dart';
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
import 'package:wakelock_plus/wakelock_plus.dart';

/// Livestream room over the MySQL backend: REST for state changes, presence
/// channel events for live updates, polling fallback while the WebSocket is
/// down. Media runs on LiveKit (see [LiveVideo]).
class LivestreamScreenController extends BaseController {
  /// This screen's connection to the room's video.
  Room? _room;
  EventsListener<RoomEvent>? _roomEvents;

  /// The host's or co-host's own camera. The go-live screen lends the one it
  /// previewed, so the camera never restarts, and stops it itself; a camera
  /// opened here is stopped here.
  LocalVideoTrack? _camera;
  bool _cameraIsLent;

  bool _loggedOut = false;
  bool _hostGone = false;

  /// The viewer's join, once sent. Leaving waits for it: in the Live tab a
  /// swipe can leave a room while its join is still on the way, and a leave
  /// that reached the server first would leave the viewer listed.
  Future<void>? _joinRequest;

  final cacheController = Get.find<AppUserCacheController>();
  final adsController = Get.find<AdsController>();

  Timer? timer;
  Timer? minViewerTimeoutTimer;
  Function? onLikeTap;

  Setting? get setting => SessionManager.instance.getSettings();

  int get minViewersThreshold => setting?.liveMinViewers ?? 0;

  int get timeoutMinutes => setting?.liveTimeout ?? 0;

  int get myUserId => SessionManager.instance.getUserID();

  /// Signed-out viewer. A guest can watch, but has no participant row on the
  /// server and no account to attribute a comment, gift, like or co-host slot
  /// to — every write path checks this and prompts for sign-in instead.
  bool get isGuest => !AuthGate.isLoggedIn;

  RxBool isMinViewerTimeout = false.obs;
  RxBool isTextEmpty = true.obs;
  bool isJoinSheetOpen = false;
  bool isFrontCamera = true;
  bool isHost;

  TextEditingController textCommentController = TextEditingController();

  LivestreamScreenController(this.liveData, this.isHost,
      {LocalVideoTrack? camera, Room? room, this.onEmbeddedExit})
      : _camera = camera,
        _cameraIsLent = camera != null,
        _handedRoom = room {
    // The go-live screen's camera may already be the back one. Starting
    // from "front" regardless made the first flip here pick the camera
    // that was already on, so nothing happened.
    if (camera != null) isFrontCamera = LiveVideo.isFront(camera);
  }

  /// Set when the room runs inside the Live tab rather than as its own
  /// screen: there is no screen to close, so leaving is handed to the tab,
  /// which moves on. [ended] is false when the video was lost rather than
  /// the live ending, so the tab can offer to try again.
  final void Function(bool ended)? onEmbeddedExit;

  bool get isEmbedded => onEmbeddedExit != null;

  /// The room on screen. The Live tab registers its room under a tag (so a
  /// room screen opened over it never picks it up from Get.put), which
  /// untagged Get.find cannot see; the room's sheets use this instead.
  static LivestreamScreenController? active;

  /// The room the gift sheet, members sheet and battle countdown belong to.
  static LivestreamScreenController get current =>
      active ?? Get.find<LivestreamScreenController>();

  /// The Live tab's preview of this room, already connected and playing.
  Room? _handedRoom;

  Future<LocalVideoTrack> _openCamera() async {
    final camera = await LiveVideo.createCamera(front: isFrontCamera);
    _camera = camera;
    _cameraIsLent = false;
    return camera;
  }

  /// Stops this screen's camera unless it was lent by the go-live screen.
  Future<void> _releaseCamera() async {
    final LocalVideoTrack? camera = _camera;
    _camera = null;
    if (camera == null || _cameraIsLent) return;
    try {
      await camera.stop();
    } catch (_) {}
  }

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

  Rx<User?> get myUser => SessionManager.instance.getUser().obs;
  Rx<Livestream> liveData;

  AudioPlayer countdownPlayer = AudioPlayer();
  AudioPlayer battleStartPlayer = AudioPlayer();
  AudioPlayer winAudioPlayer = AudioPlayer();

  List<User> usersList = [];

  String get roomId => liveData.value.roomID ?? '';

  StreamSubscription<RealtimeEvent>? _realtimeSub;
  Timer? _pollTimer;

  /// When the last realtime event landed on this room's channel. Drives the
  /// safety-net reconcile in [_startRealtime] — see the note there.
  DateTime _lastRealtimeEventAt = DateTime.now();
  DateTime _lastPollAt = DateTime.now();
  Timer? _likeFlushTimer;
  String? _presenceChannelName;
  int _lastCommentId = 0;
  int _pendingLikes = 0;
  int _knownLikeCount = 0;

  @override
  void onInit() {
    super.onInit();
    active = this;

    totalBattleSecond =
        Duration(minutes: liveData.value.battleDuration).inSeconds;
    remainingBattleSeconds.value = totalBattleSecond;
    _knownLikeCount = liveData.value.likeCount ?? 0;
    loginRoom();
    initAudioPlayer();
    _startRealtime();
    if (isHost) {
      _fetchFullState();
    }

    WakelockPlus.enable();
    // Guests have no per-user topic subscription to suppress.
    if (!isGuest) {
      FirebaseNotificationManager.instance
          .unsubscribeToTopic(topic: myUserId.toString());
    }
  }

  @override
  void onClose() {
    super.onClose();
    if (identical(active, this)) active = null;
    // The Live tab sits beside Snaps, which keeps the screen awake for as
    // long as the app is open; a tab room switching that off on every swipe
    // let the screen sleep under the reels.
    if (!isEmbedded) WakelockPlus.disable();
    timer?.cancel();
    minViewerTimeoutTimer?.cancel();
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
    // Poll cadence is what everyone else in the room waits on when the
    // WebSocket transport is off, so it is the floor on how fast a comment,
    // gift or join-request can reach them. 4s meant a mean 2s / worst 4s delay
    // on top of the request itself. Polls are cheap delta fetches
    // (after_comment_id), so 1.2s is affordable and cuts that ~3x.
    //
    // This timer is skipped entirely once realtime connects — turning on
    // BROADCAST_DRIVER + realtimeAppKey removes this path, and its load, for
    // good. Revisit the interval only if you ship without realtime at scale.
    _pollTimer = Timer.periodic(const Duration(milliseconds: 1200), (_) {
      if (!RealtimeService.instance.isConnected) {
        _pollState();
        return;
      }
      // An open socket is not proof that events are actually arriving. If the
      // backend is still on BROADCAST_DRIVER=log, or channel auth / app keys
      // mismatch, the client connects happily and then receives nothing —
      // and cutting polling off on `isConnected` alone would silently freeze
      // the entire room with no error anywhere. Reconcile whenever the stream
      // has been quiet for 10s so a misconfigured transport degrades to
      // "slightly behind" instead of "completely broken".
      if (DateTime.now().difference(_lastRealtimeEventAt) >
          const Duration(seconds: 10)) {
        _pollState();
        return;
      }
      // The host's polls are its heartbeat: the server stops listing a room
      // whose host it has not heard from in two minutes. A busy room keeps
      // the socket chatty enough that the host would otherwise never poll.
      if (isHost &&
          DateTime.now().difference(_lastPollAt) >
              const Duration(seconds: 20)) {
        _pollState();
      }
    });
    // Hearts are batched so a rapid tap burst is one request. 4s of batching
    // was long enough that a reaction could take ~4s to even leave the device,
    // then up to another poll interval to appear for anyone else — the worst
    // end-to-end delay of any live action. 600ms still coalesces a burst into
    // a single call while feeling immediate.
    _likeFlushTimer = Timer.periodic(const Duration(milliseconds: 600), (_) {
      _flushPendingLikes();
    });
  }

  void _onRealtimeEvent(RealtimeEvent event) {
    if (event.channel != _presenceChannelName) return;
    _lastRealtimeEventAt = DateTime.now();
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
        // Usually the video room closing gets there first; this covers a
        // close that never reached LiveKit.
        Loggers.info('livestream.ended received for room $roomId');
        _onHostLeft();
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

  /// Pulls the next delta now rather than on the next tick. Used right after a
  /// local action that the server turns into state we do not get back inline
  /// (a gift becomes a gift comment, a join request becomes a user state), so
  /// the actor sees the result immediately instead of up to a full interval
  /// later. No-op when realtime is live — the event beats any poll.
  void _pollSoon() {
    if (RealtimeService.instance.isConnected) return;
    _pollState();
  }

  Future<void> _pollState() async {
    if (roomId.isEmpty) return;
    _lastPollAt = DateTime.now();
    try {
      final res = await LivestreamService.instance
          .fetchStreamState(roomId: roomId, afterCommentId: _lastCommentId);
      if (res.status != true && res.message == 'livestream_ended') {
        _pollTimer?.cancel();
        _onHostLeft();
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
  // VIDEO ROOM (LiveKit)
  // ---------------------------------------------------------------------

  /// Leaves the room once, however the screen is left: the stop sheet, the
  /// viewer's exit, the host ending and the screen closing all arrive here.
  /// It used to run for each of them, telling the server a viewer left twice
  /// (the watching count dropped by two) and ending a host's room twice.
  Future<void> logoutRoom() async {
    if (_loggedOut) return;
    _loggedOut = true;
    if (isHost) {
      _endStreamOnServer();
      // Placed here rather than in hostEndStream() so every way a host can
      // leave is covered — the stop sheet, the minimum-viewer timeout, and
      // backing out of the screen all funnel through this method. The call is
      // a no-op unless a recording is actually running.
      //
      // Awaited so the recorder has closed the file before the camera is
      // stopped below: an MP4 whose source goes away mid-write never gets its
      // header and is unplayable. Only the stop is awaited — the upload it
      // starts runs on past this screen.
      await LiveRecordingManager.instance
          .stopAndSave(description: liveData.value.description);
    } else if (roomId.isNotEmpty && _joinRequest != null) {
      // Never joined (swiped past before the video connected): nothing to
      // leave, and a leave would take one off the room's watching count.
      final String room = roomId;
      final Future<void> joined = _joinRequest!;
      () async {
        try {
          await joined;
          await LivestreamService.instance.leaveLivestream(roomId: room);
        } catch (e) {
          Loggers.error('leaveLivestream failed: $e');
        }
      }();
    }
    await _closeVideo();
  }

  Future<void> _closeVideo() async {
    stopListenEvent();
    final Room? room = _room;
    _room = null;
    await LiveVideo.close(room);
    await _releaseCamera();
  }

  /// Joins the room's video, then publishes (host) or registers as a viewer.
  Future<void> loginRoom() async {
    final Room? handed = _handedRoom;
    _handedRoom = null;
    if (roomId.isEmpty) {
      await LiveVideo.close(handed);
      return;
    }
    final Room room;
    try {
      if (handed != null &&
          handed.connectionState == lk.ConnectionState.connected) {
        // Joining from the Live tab: the video is already playing.
        room = handed;
      } else {
        await LiveVideo.close(handed);
        room = await LiveVideo.connect(roomId);
      }
    } on LiveVideoUnavailable catch (e) {
      Loggers.error('Live video unavailable: ${e.reason}');
      _onCannotConnect(ended: e.roomEnded);
      return;
    } catch (e) {
      Loggers.error('Live video connect failed: $e');
      _onCannotConnect(ended: false);
      return;
    }
    if (isClosed || _loggedOut) {
      await LiveVideo.close(room);
      return;
    }
    _room = room;
    startListenEvent();
    _showRemoteCameras(room);

    if (isHost) {
      await startHostPublish();
      return;
    }

    // Audience: register with the backend and pull the full room snapshot
    // (participants + last comments) in one round trip.
    try {
      final join = LivestreamService.instance.joinLivestream(roomId: roomId);
      _joinRequest = join.then((_) {}, onError: (_) {});
      final res = await join;
      if (_loggedOut) return;
      if (res.status == true) {
        _applyStateResult(res, replaceComments: true);
      } else if (res.message == 'livestream_ended') {
        _onHostLeft();
      }
    } catch (e) {
      Loggers.error('joinLivestream failed: $e');
    }
  }

  /// There is no video for this room. A host's room is pointless without
  /// it, so it is ended rather than left listed with nothing to watch.
  void _onCannotConnect({required bool ended}) {
    if (isClosed) return;
    if (isHost) _endStreamOnServer();
    _leaveScreen(ended: ended);
    if (isEmbedded) return; // the Live tab says so on the room itself
    showSnackBar(ended
        ? LKey.livestreamHasEnded.tr
        : LKey.liveVideoUnavailable.tr);
  }

  /// Closes this screen along with any sheet or snackbar over it. A plain
  /// Get.back() only closes an open snackbar and leaves the screen up, so
  /// "Livestream has ended" used to show over a dead room. In the Live tab
  /// there is no screen to close: a sheet over the room is closed and the
  /// tab moves on.
  void _leaveScreen({bool ended = true}) {
    final exit = onEmbeddedExit;
    if (exit == null) {
      Get.back(closeOverlays: true);
      return;
    }
    if (Get.isBottomSheetOpen == true) Get.back();
    exit(ended);
  }

  void startListenEvent() {
    final Room? room = _room;
    if (room == null) return;
    _roomEvents?.dispose();
    final EventsListener<RoomEvent> listener = room.createListener();
    _roomEvents = listener;
    listener
      ..on<TrackSubscribedEvent>((event) {
        final track = event.track;
        if (track is VideoTrack) _putCamera(event.participant.identity, track);
      })
      ..on<TrackUnsubscribedEvent>((event) {
        if (event.track is VideoTrack) {
          streamViews.removeWhere(
              (view) => view.streamId == event.participant.identity);
        }
      })
      ..on<ParticipantDisconnectedEvent>(
          (event) => _onParticipantLeft(event.participant))
      ..on<RoomDisconnectedEvent>((event) => _onDisconnected(event.reason));
  }

  void stopListenEvent() {
    _roomEvents?.dispose();
    _roomEvents = null;
  }

  /// Cameras that were already on when this screen joined.
  void _showRemoteCameras(Room room) {
    for (final participant in room.remoteParticipants.values) {
      final VideoTrack? track = LiveVideo.cameraOf(participant);
      if (track != null) _putCamera(participant.identity, track);
    }
  }

  /// Shows [identity]'s camera. Views are keyed by identity, which is the
  /// user id; the host's view leads every layout.
  void _putCamera(String identity, VideoTrack track) {
    final view = StreamView(
        identity,
        track is LocalVideoTrack
            // This phone's own camera, mirrored only while it is the front.
            ? LiveVideo.selfView(track, key: ObjectKey(track))
            : VideoTrackRenderer(track,
                key: ObjectKey(track), fit: VideoViewFit.cover),
        false);
    final int index = streamViews.indexWhere((v) => v.streamId == identity);
    if (index != -1) {
      view.isMuted = streamViews[index].isMuted;
      streamViews[index] = view;
    } else if (identity == '${liveData.value.hostId}') {
      streamViews.insert(0, view);
    } else {
      streamViews.add(view);
    }
  }

  void _onParticipantLeft(RemoteParticipant participant) {
    streamViews.removeWhere((view) => view.streamId == participant.identity);
    final int? userId = LiveVideo.userIdOf(participant);
    if (userId == null) return;
    if (userId == liveData.value.hostId) {
      _onHostLeft();
      return;
    }
    // A co-host who dropped off the video gives up their slot; only the host
    // tidies that up.
    if (isHost && (liveData.value.coHostIds?.contains(userId) ?? false)) {
      updateUserStateToFirestore(userId,
          type: LivestreamUserType.audience,
          audioStatus: VideoAudioStatus.on,
          videoStatus: VideoAudioStatus.on);
    }
  }

  /// LiveKit gave up on the connection, or the room was closed (the server
  /// closes it when the host ends the live).
  void _onDisconnected(DisconnectReason? reason) {
    if (_loggedOut || isClosed) return;
    Loggers.info('Live video disconnected: $reason');
    if (isHost) {
      // The broadcast has stopped either way: end it and show the summary.
      hostEndStream();
      return;
    }
    _onHostLeft(connectionLost: reason != DisconnectReason.roomDeleted);
  }

  /// The host is gone — they ended the live, or their app did. Viewers are
  /// taken out of the room. A co-host sees their summary, which closes the
  /// screen when dismissed because the room is emptied here (closing it
  /// first as well used to pop the screen underneath too).
  void _onHostLeft({bool connectionLost = false}) {
    if (isHost || _hostGone || isClosed) return;
    _hostGone = true;
    final bool wasCoHost = liveUsersStates.any((element) =>
        element.userId == myUserId &&
        element.type == LivestreamUserType.coHost);
    if (wasCoHost) {
      streamEnded();
    } else {
      _leaveScreen(ended: !connectionLost);
    }
    logoutRoom();
    liveData.value = Livestream();
    // The Live tab says so on the room itself.
    if (!wasCoHost && !isEmbedded) {
      showSnackBar(connectionLost
          ? LKey.liveConnectionLost.tr
          : LKey.livestreamHasEnded.tr);
    }
  }

  Future<void> startHostPublish() async {
    final Room? room = _room;
    if (room == null || roomId.isEmpty) return;
    try {
      // Rejoining your own live (from the Live tab or a notification) comes
      // without the go-live screen's camera: open one.
      if (_camera == null) {
        if (!await requestPermission()) {
          _leaveScreen();
          _showPermissionSheet();
          return;
        }
        await _openCamera();
      }
      await room.localParticipant?.publishVideoTrack(_camera!);
      await room.localParticipant?.setMicrophoneEnabled(true);
    } catch (e) {
      Loggers.error('Could not publish the live: $e');
      _onCannotConnect(ended: false);
      return;
    }
    _putCamera('$myUserId', _camera!);
    startMinViewerTimeoutCheck(); //  Check time to Min. Viewers Required to continue live
    pushNotificationToFollowers(liveData.value);
    _captureRetentionSnapshot();
    // Records what is being broadcast so the live is not gone when the room
    // closes. Owned by LiveRecordingManager, not this controller: the upload
    // runs for minutes after the host is moved to the end screen and this
    // controller is disposed.
    LiveRecordingManager.instance.start(roomId: roomId, camera: _camera!);
  }

  /// Captures the one frame that outlives the broadcast.
  ///
  /// Taken a few seconds after publishing starts rather than at the end: by
  /// then the camera has real frames, whereas at end-of-stream the capture
  /// would race the teardown of the camera being captured.
  ///
  /// Entirely best-effort. A failure here must never disturb a live broadcast,
  /// so every path swallows its error.
  void _captureRetentionSnapshot() {
    Future.delayed(const Duration(seconds: 5), () async {
      final LocalVideoTrack? camera = _camera;
      if (isClosed || roomId.isEmpty || camera == null) return;
      try {
        final ByteBuffer frame = await camera.mediaStreamTrack.captureFrame();
        final Uint8List bytes = frame.asUint8List();
        if (bytes.isEmpty) {
          Loggers.error('Livestream snapshot capture was empty');
          return;
        }

        // captureFrame writes a PNG.
        final dir = await getTemporaryDirectory();
        final file = File('${dir.path}/live_${roomId.replaceAll('/', '_')}.png');
        await file.writeAsBytes(bytes, flush: true);

        await LivestreamService.instance
            .uploadLivestreamSnapshot(roomId: roomId, snapshot: XFile(file.path));
      } catch (e) {
        Loggers.error('Livestream snapshot upload failed: $e');
      }
    });
  }

  /// Stops sending this phone's camera and microphone into the room.
  Future<void> _stopPublishing() async {
    final Room? room = _room;
    if (room != null) {
      await LiveVideo.stopMicrophone(room);
      try {
        await room.localParticipant?.unpublishAllTracks();
      } catch (e) {
        Loggers.error('unpublish failed: $e');
      }
    }
    await _releaseCamera();
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

  void toggleCamera() => toggleFlipCamera();

  bool _flipping = false;

  /// Flips the camera in place (see [LiveVideo.flipCamera]): the stream to
  /// viewers and any recording carry on through the switch, where restarting
  /// the track stopped the picture for a moment and left a recording on the
  /// old, stopped camera.
  Future<void> toggleFlipCamera() async {
    final LocalVideoTrack? camera = _camera;
    if (camera == null || _flipping) return;
    _flipping = true;
    try {
      isFrontCamera = await LiveVideo.flipCamera(camera);
      // Same track, so the view updates in place; only its mirroring changes.
      if (streamViews.any((view) => view.streamId == '$myUserId')) {
        _putCamera('$myUserId', camera);
      }
    } catch (e) {
      Loggers.error('Camera switch failed: $e');
    } finally {
      _flipping = false;
    }
  }

  void toggleMic(LivestreamUserState? state) async {
    if (state?.audioStatus == VideoAudioStatus.offByHost) {
      return showSnackBar(LKey.theHostHasTurnedOffYourAudio);
    }

    bool isAudioOn = state?.audioStatus == VideoAudioStatus.on;

    if (isAudioOn) {
      updateUserStateToFirestore(myUserId,
          audioStatus: VideoAudioStatus.offByMe);
      await _room?.localParticipant?.setMicrophoneEnabled(false);
    } else {
      updateUserStateToFirestore(myUserId, audioStatus: VideoAudioStatus.on);
      await _room?.localParticipant?.setMicrophoneEnabled(true);
    }
  }

  void toggleVideo(LivestreamUserState? state) async {
    if (state?.videoStatus == VideoAudioStatus.offByHost) {
      return showSnackBar(LKey.theHostHasTurnedOffYourVideo.tr);
    }
    bool isVideoOn = state?.videoStatus == VideoAudioStatus.on;
    // Muting keeps the camera published, so turning it back on is instant.
    if (isVideoOn) {
      updateUserStateToFirestore(myUserId,
          videoStatus: VideoAudioStatus.offByMe);
      await _camera?.mute();
    } else {
      updateUserStateToFirestore(myUserId, videoStatus: VideoAudioStatus.on);
      await _camera?.unmute();
    }
  }

  /// Mutes someone in the room for this viewer only, by no longer receiving
  /// their audio.
  void toggleStreamAudio(int? userId) {
    int index =
        streamViews.indexWhere((element) => element.ownerUserId == userId);
    if (index == -1) return;
    StreamView view = streamViews[index];

    final participant = _room?.getParticipantByIdentity(view.streamId);
    if (participant is RemoteParticipant) {
      for (final publication in participant.audioTrackPublications) {
        view.isMuted ? publication.enable() : publication.disable();
      }
    }
    view.isMuted = !view.isMuted;
    streamViews[index] = view;
    streamViews.refresh();
  }

  /// Hearts: instant local animation, batched server flush (one addLikes call
  /// per few seconds instead of one write per tap).
  void onLikeButtonTap() async {
    // Guests watch read-only; every write into the room needs an account.
    if (!AuthGate.check()) return;
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
    if (!AuthGate.check()) return;
    String comment = textCommentController.text.trim();
    textCommentController.clear();
    isTextEmpty.value = true;
    if (comment.isEmpty) return;
    _sendComment(type: LivestreamCommentType.text, comment: comment);
  }

  void onGiftTap(GiftType type,
      {BattleView battleViewType = BattleView.red,
      List<AppUser> users = const []}) {
    if (!AuthGate.check()) return;
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
              // The gift becomes a gift comment server-side; fetch it now so
              // the sender sees their own gift land straight away.
              _pollSoon();
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
      final res = await LivestreamService.instance.sendComment(
          roomId: roomId,
          comment: comment,
          commentType: type.value,
          receiverId: receiverId);
      // Echo our own comment straight into the list instead of waiting for the
      // next poll to fetch back something we already have. _appendComment
      // dedupes on id and advances _lastCommentId, so the poll that later
      // returns this same row is a no-op.
      final sent = res.comment;
      if (res.status == true && sent != null) {
        _appendComment(sent);
      }
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
    if (!isPermissionGranted) {
      _showPermissionSheet();
      return;
    }
    final Room? room = _room;
    if (room == null) return;
    try {
      await _ensureCanPublish();
      if (_camera == null) await _openCamera();
      await _room?.localParticipant?.publishVideoTrack(_camera!);
      await _room?.localParticipant?.setMicrophoneEnabled(true);
    } catch (e) {
      Loggers.error('Co-host publish failed: $e');
      showSnackBar(LKey.somethingWentWrong.tr);
      return;
    }
    _putCamera('$streamId', _camera!);

    // Server side already flipped this user to CO-HOST (that's what
    // triggered this publish) — just announce it in the feed.
    _sendComment(type: LivestreamCommentType.joinedCoHost);
  }

  /// The server lets a new co-host publish on their live connection before
  /// it tells the room about the promotion, but that change can still be on
  /// its way. Waits for it briefly, then rejoins with a fresh token (which
  /// carries the permission) as a last resort.
  Future<void> _ensureCanPublish() async {
    final Room? room = _room;
    if (room == null) return;
    if (room.localParticipant?.permissions.canPublish == true) return;
    final granted = Completer<void>();
    final listener = room.createListener();
    listener.on<ParticipantPermissionsUpdatedEvent>((event) {
      if (event.permissions.canPublish && !granted.isCompleted) {
        granted.complete();
      }
    });
    try {
      await granted.future.timeout(const Duration(seconds: 6));
      return;
    } on TimeoutException {
      Loggers.info('Publish permission did not arrive; rejoining');
    } finally {
      await listener.dispose();
    }
    stopListenEvent();
    await LiveVideo.close(room);
    _room = await LiveVideo.connect(roomId);
    startListenEvent();
    _showRemoteCameras(_room!);
  }

  void _showPermissionSheet() {
    Get.bottomSheet(ConfirmationSheet(
      title: LKey.cameraMicrophonePermissionTitle.tr,
      description: LKey.cameraMicrophonePermissionDescription.tr,
      onTap: openAppSettings,
    ));
  }

  void closeCoHostStream(int? streamId) {
    StreamView? view = streamViews
        .firstWhereOrNull((element) => element.streamId == '$streamId');
    if (view != null) {
      _stopPublishing();
      // The joined-co-host line disappears locally.
      comments.removeWhere((element) =>
          element.senderId == myUserId &&
          element.commentType == LivestreamCommentType.joinedCoHost);
      updateUserStateToFirestore(streamId,
          type: LivestreamUserType.audience,
          audioStatus: VideoAudioStatus.offByMe,
          videoStatus: VideoAudioStatus.offByMe);
      streamViews.removeWhere((element) => element.streamId == '$streamId');
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
      // toJson() runs before Loggers can no-op it, and this fires on every
      // participant state change for the whole livestream — guarded so
      // release builds don't serialize a model per event.
      if (kDebugMode) {
        Loggers.info('Updating state for userId: ${newState.toJson()}');
      }
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
      _leaveScreen();
      Get.off(() => LiveStreamEndScreen(
          userState: userState,
          isHost: isHost,
          viewers: viewers,
          snapshot: liveData.value.snapshot));
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
            _leaveScreen();
          }
        });
      }
    }
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
          // A co-host's summary sheet closes the screen itself once dismissed
          // (it checks for an emptied room); otherwise close it now. Zego used
          // to do this by reporting the host's stream gone on logout.
          liveData.value = Livestream();
          if (Get.isBottomSheetOpen != true) _leaveScreen();
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

/// One person's camera in the room. [streamId] is their LiveKit identity,
/// which is their user id.
class StreamView {
  String streamId;
  Widget streamView;
  bool isMuted;

  StreamView(this.streamId, this.streamView, this.isMuted);

  /// The owner of this view, or null if the id isn't one we recognise.
  /// Under Zego the host published under the room id ('<hostId>_<millis>'),
  /// so ids are still read up to the first '_'.
  int? get ownerUserId => int.tryParse(streamId.split('_').first);
}

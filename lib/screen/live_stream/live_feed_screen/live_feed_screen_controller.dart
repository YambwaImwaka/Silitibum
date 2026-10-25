import 'dart:async';

import 'package:flutter/widgets.dart';
import 'package:get/get.dart';
import 'package:livekit_client/livekit_client.dart'
    show
        DisconnectReason,
        EventsListener,
        ParticipantDisconnectedEvent,
        Room,
        RoomDisconnectedEvent,
        RoomEvent,
        TrackSubscribedEvent,
        VideoTrack,
        VideoTrackRenderer,
        VideoViewFit;
import 'package:shortzz/common/controller/app_user_cache_controller.dart';
import 'package:shortzz/common/controller/base_controller.dart';
import 'package:shortzz/common/functions/auth_gate.dart';
import 'package:shortzz/common/manager/logger.dart';
import 'package:shortzz/common/manager/session_manager.dart';
import 'package:shortzz/common/service/api/livestream_service.dart';
import 'package:shortzz/common/service/live/live_video.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/model/livestream/app_user.dart';
import 'package:shortzz/model/livestream/livestream.dart';
import 'package:shortzz/screen/dashboard_screen/dashboard_screen_controller.dart';
import 'package:shortzz/screen/live_stream/create_live_stream_screen/create_live_stream_screen.dart';
import 'package:shortzz/screen/live_stream/livestream_screen/audience/live_stream_audience_screen.dart';
import 'package:shortzz/screen/live_stream/livestream_screen/host/livestream_host_screen.dart';
import 'package:shortzz/screen/live_stream/livestream_screen/livestream_screen_controller.dart';

/// A fresh list of live rooms in the order the viewer already sees them:
/// rooms on screen keep their place, new rooms join the end, and rooms no
/// longer live are dropped — except [currentRoom], which stays so the viewer
/// sees why it stopped. [currentEnded] says whether that happened.
({List<Livestream> rooms, bool currentEnded}) mergeLiveRooms(
    List<Livestream> shown, List<Livestream> fresh, String? currentRoom) {
  final Map<String?, Livestream> byRoom = {
    for (final stream in fresh) stream.roomID: stream
  };
  final List<Livestream> rooms = [];
  bool currentEnded = false;
  for (final stream in shown) {
    final Livestream? update = byRoom.remove(stream.roomID);
    if (update != null) {
      rooms.add(update);
    } else if (currentRoom != null && stream.roomID == currentRoom) {
      rooms.add(stream);
      currentEnded = true;
    }
  }
  rooms.addAll(byRoom.values);
  return (rooms: rooms, currentEnded: currentEnded);
}

/// The Live tab: one live room at a time, full screen and already playing —
/// swipe up for the next, tap to join — and a plain "no one is live" when
/// nobody is, instead of stale cards.
///
/// The playing room is a preview: a hidden participant in the room's video
/// that plays the host's camera without joining on the server, so it neither
/// counts as a viewer nor shows up in the host's audience. Joining hands over
/// to the full room screen.
class LiveFeedScreenController extends BaseController
    with WidgetsBindingObserver {
  static const int liveTabIndex = DashboardScreenController.liveTab;

  final RxList<Livestream> streams = <Livestream>[].obs;
  final RxInt currentIndex = 0.obs;
  final RxBool hasLoaded = false.obs;
  final PageController pageController = PageController();

  /// The current room's video, once the host's camera arrives.
  final Rx<Widget?> previewView = Rx<Widget?>(null);

  /// Set once the host's camera arrives; until then the room's placeholder
  /// shows.
  final RxBool isRendering = false.obs;

  /// Rooms that ended while they were on screen.
  final RxSet<String> endedRooms = <String>{}.obs;

  final AppUserCacheController appUserCacheController =
      Get.find<AppUserCacheController>();

  Timer? _refreshTimer;
  Timer? _routeWatchdog;
  Timer? _advanceTimer;

  // What should be playing, and what is. Every join and leave runs through
  // _ops, one at a time, so a fast swipe can never leave two rooms
  // half-joined.
  String? _wantedRoom;
  String? _playingRoom;
  Room? _previewRoom;
  EventsListener<RoomEvent>? _previewEvents;
  Future<void> _ops = Future.value();

  bool _appResumed = true;
  bool _navigating = false;
  String? _dashboardRoute;

  Timer? _dropTimer;

  Livestream? get currentStream =>
      currentIndex.value < streams.length ? streams[currentIndex.value] : null;

  DashboardScreenController? get _dashboard =>
      Get.isRegistered<DashboardScreenController>()
          ? Get.find<DashboardScreenController>()
          : null;

  bool get _tabSelected =>
      (_dashboard?.selectedPageIndex.value ?? liveTabIndex) == liveTabIndex;

  bool get _coveredByRoute =>
      _dashboardRoute != null && Get.currentRoute != _dashboardRoute;

  bool get _isVisible =>
      _tabSelected && _appResumed && !_navigating && !_coveredByRoute;

  @override
  void onInit() {
    super.onInit();
    WidgetsBinding.instance.addObserver(this);
    final dashboard = _dashboard;
    if (dashboard != null) {
      ever<int>(dashboard.selectedPageIndex, (_) => _onVisibilityChanged());
    }
  }

  @override
  void onReady() {
    super.onReady();
    _dashboardRoute = Get.currentRoute;
    fetchStreams();
    _onVisibilityChanged();
    // Notices any other screen opening over the tab (a notification, a deep
    // link) and stops the room playing behind it.
    _routeWatchdog = Timer.periodic(
        const Duration(seconds: 1), (_) => _syncPreview());
  }

  @override
  void onClose() {
    WidgetsBinding.instance.removeObserver(this);
    _refreshTimer?.cancel();
    _routeWatchdog?.cancel();
    _advanceTimer?.cancel();
    _dropTimer?.cancel();
    _wantedRoom = null;
    _ops = _ops.then((_) => _leave());
    pageController.dispose();
    super.onClose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    // `inactive` is the notification shade or a system dialog over the app;
    // only leaving the app stops the room.
    _appResumed = state == AppLifecycleState.resumed ||
        state == AppLifecycleState.inactive;
    _onVisibilityChanged();
  }

  void _onVisibilityChanged() {
    if (_isVisible) {
      _refreshTimer ??= Timer.periodic(
          const Duration(seconds: 15), (_) => fetchStreams(silent: true));
    } else {
      _refreshTimer?.cancel();
      _refreshTimer = null;
    }
    _syncPreview();
  }

  Future<void> fetchStreams({bool silent = false}) async {
    if (!silent) isLoading.value = true;
    try {
      final fresh = await LivestreamService.instance.fetchLivestreams();
      _assignHostUsers(fresh);
      _merge(fresh);
    } catch (e) {
      Loggers.error('fetchLivestreams failed: $e');
    } finally {
      isLoading.value = false;
      hasLoaded.value = true;
    }
  }

  void _merge(List<Livestream> fresh) {
    final String? currentRoom = currentStream?.roomID;
    final merged = mergeLiveRooms(streams, fresh, currentRoom);
    if (merged.currentEnded) _onRoomEnded(currentRoom);
    _replaceStreams(merged.rooms, keepRoom: currentRoom);
  }

  void _replaceStreams(List<Livestream> next, {String? keepRoom}) {
    final int keepAt = keepRoom == null
        ? -1
        : next.indexWhere((stream) => stream.roomID == keepRoom);
    streams.assignAll(next);
    final int index = keepAt >= 0
        ? keepAt
        : currentIndex.value.clamp(0, next.isEmpty ? 0 : next.length - 1);
    if (index != currentIndex.value) {
      currentIndex.value = index;
      if (pageController.hasClients) pageController.jumpToPage(index);
    }
    _syncPreview();
  }

  void _assignHostUsers(List<Livestream> list) {
    final Map<int, AppUser> users = {
      for (final user in appUserCacheController.users)
        if (user.userId != null) user.userId!: user
    };
    for (final stream in list) {
      stream.hostUser = users[stream.hostId];
      if (stream.hostUser == null && (stream.hostId ?? -1) != -1) {
        appUserCacheController.fetchUserIfNeeded(stream.hostId!);
      }
    }
  }

  void onPageChanged(int index) {
    currentIndex.value = index;
    _advanceTimer?.cancel();
    _syncPreview();
    // Rooms that ended have nothing more to show. They go once the swipe
    // has settled — removing a page mid-gesture would yank the list.
    if (endedRooms.isNotEmpty) {
      _dropTimer?.cancel();
      _dropTimer = Timer(const Duration(milliseconds: 500), _dropEndedRooms);
    }
  }

  void _dropEndedRooms() {
    final String? currentRoom = currentStream?.roomID;
    final List<Livestream> kept = streams
        .where((stream) =>
            stream.roomID == currentRoom || !endedRooms.contains(stream.roomID))
        .toList();
    if (kept.length != streams.length) {
      _replaceStreams(kept, keepRoom: currentRoom);
    }
  }

  void _onRoomEnded(String? roomId) {
    if (roomId == null || endedRooms.contains(roomId)) return;
    endedRooms.add(roomId);
    if (roomId != currentStream?.roomID) return;
    _syncPreview();
    // Move on by itself, as an ended room is a dead end.
    _advanceTimer?.cancel();
    _advanceTimer = Timer(const Duration(milliseconds: 1800), () {
      if (currentStream?.roomID != roomId) return;
      final int next = currentIndex.value + 1;
      if (next < streams.length && pageController.hasClients) {
        pageController.animateToPage(next,
            duration: const Duration(milliseconds: 350),
            curve: Curves.easeOut);
      } else {
        _replaceStreams(
            streams.where((stream) => stream.roomID != roomId).toList());
      }
    });
  }

  // ---------------------------------------------------------------------
  // Preview playback
  // ---------------------------------------------------------------------

  void _syncPreview() {
    final Livestream? stream = currentStream;
    final String? room = stream?.roomID;
    final bool show = _isVisible &&
        room != null &&
        room.isNotEmpty &&
        !endedRooms.contains(room);
    final String? wanted = show ? room : null;
    if (wanted == _wantedRoom) return;
    _wantedRoom = wanted;
    _ops = _ops.then((_) => _reconcile()).catchError((Object e) {
      Loggers.error('Live preview: $e');
    });
  }

  Future<void> _reconcile() async {
    final String? wanted = _wantedRoom;
    if (wanted == _playingRoom) return;
    if (_playingRoom != null) await _leave();
    if (wanted != null && wanted == _wantedRoom) await _join(wanted);
  }

  Future<void> _join(String roomId) async {
    final Room room;
    try {
      room = await LiveVideo.connect(roomId, preview: true);
    } on LiveVideoUnavailable catch (e) {
      Loggers.error('Live preview unavailable: ${e.reason}');
      if (e.roomEnded) _onRoomEnded(roomId);
      return;
    } catch (e) {
      Loggers.error('Live preview connect failed: $e');
      return;
    }
    // The viewer may have swiped on while it connected.
    if (_wantedRoom != roomId) {
      await LiveVideo.close(room);
      return;
    }
    _previewRoom = room;
    _playingRoom = roomId;
    isRendering.value = false;

    final int? hostId =
        streams.firstWhereOrNull((stream) => stream.roomID == roomId)?.hostId;
    final String host = '$hostId';
    final EventsListener<RoomEvent> listener = room.createListener();
    _previewEvents = listener;
    listener
      ..on<TrackSubscribedEvent>((event) {
        final track = event.track;
        if (event.participant.identity == host && track is VideoTrack) {
          _showPreview(roomId, track);
        }
      })
      ..on<ParticipantDisconnectedEvent>((event) {
        if (event.participant.identity == host) _onRoomEnded(roomId);
      })
      ..on<RoomDisconnectedEvent>((event) {
        if (event.reason == DisconnectReason.roomDeleted) _onRoomEnded(roomId);
      });

    // The host's camera was usually on before the preview joined.
    final hostParticipant = room.getParticipantByIdentity(host);
    final VideoTrack? camera =
        hostParticipant == null ? null : LiveVideo.cameraOf(hostParticipant);
    if (camera != null) _showPreview(roomId, camera);
  }

  void _showPreview(String roomId, VideoTrack track) {
    if (_playingRoom != roomId) return;
    previewView.value = VideoTrackRenderer(track,
        key: ObjectKey(track), fit: VideoViewFit.cover);
    isRendering.value = true;
    _prefetchNext();
  }

  /// While one live plays, the next one's token is fetched, so swiping to it
  /// skips that round trip. Only the token: holding a hidden connection to
  /// the next room as well would double the LiveKit minutes every viewer
  /// uses.
  void _prefetchNext() {
    final int next = currentIndex.value + 1;
    if (next >= streams.length) return;
    final String? roomId = streams[next].roomID;
    if (roomId == null || roomId.isEmpty || endedRooms.contains(roomId)) return;
    LiveVideo.prefetchToken(roomId, preview: true);
  }

  /// Gives the playing preview to the room screen, so joining shows the video
  /// that is already playing instead of connecting all over again.
  Room? _takePreview(String? roomId) {
    if (roomId == null || roomId != _playingRoom) return null;
    final Room? room = _previewRoom;
    _previewRoom = null;
    _playingRoom = null;
    final listener = _previewEvents;
    _previewEvents = null;
    listener?.dispose();
    previewView.value = null;
    isRendering.value = false;
    return room;
  }

  Future<void> _leave() async {
    final Room? room = _previewRoom;
    _previewRoom = null;
    _playingRoom = null;
    previewView.value = null;
    isRendering.value = false;
    final listener = _previewEvents;
    _previewEvents = null;
    await listener?.dispose();
    await LiveVideo.close(room);
  }

  // ---------------------------------------------------------------------
  // Leaving the tab for a room or for going live
  // ---------------------------------------------------------------------

  Future<void> joinCurrent() async {
    final Livestream? stream = currentStream;
    if (stream == null || endedRooms.contains(stream.roomID)) return;
    if (_navigating) return;
    final bool mine = stream.hostId == SessionManager.instance.getUserID();
    // Taken before _openOver stops the preview, which would close it.
    final Room? playing = mine ? null : _takePreview(stream.roomID);
    // Watching is open to everyone; the room asks a guest to sign in at the
    // point they try to comment, gift or co-host.
    await _openOver(() async {
      if (mine) {
        await Get.to(() => LivestreamHostScreen(isHost: true, livestream: stream));
      } else {
        await Get.to(() => LiveStreamAudienceScreen(
            isHost: false, livestream: stream, room: playing));
      }
    });
  }

  Future<void> onGoLive() async {
    // Broadcasting needs an account; ask at the button rather than at a
    // dead end after the camera has already opened.
    if (!AuthGate.check()) return;
    final myUser = SessionManager.instance.getUser();
    final bool alreadyLive =
        streams.any((stream) => stream.hostId == myUser?.id);
    if (myUser?.isDummy == 1 && alreadyLive) {
      return showSnackBar(LKey.yourProfileIsAlreadyInUseForDummyEtc.tr);
    }
    // A stale previous stream is ended server-side by createLivestream.
    await _openOver(() async {
      await Get.to(() => const CreateLiveStreamScreen());
    });
  }

  /// Stops the preview, opens a screen that has the camera or the room
  /// itself, and resumes once that screen has fully let go of it.
  Future<void> _openOver(Future<void> Function() open) async {
    if (_navigating) return;
    _navigating = true;
    _syncPreview();
    try {
      await _ops;
      await open();
    } finally {
      // The room screen leaves its room when it is disposed, which is after
      // its route has popped; previewing again before then would race it.
      for (int i = 0;
          i < 30 && Get.isRegistered<LivestreamScreenController>();
          i++) {
        await Future.delayed(const Duration(milliseconds: 100));
      }
      _navigating = false;
      await fetchStreams(silent: true);
      _syncPreview();
    }
  }
}

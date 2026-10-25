import 'dart:async';

import 'package:flutter/widgets.dart';
import 'package:get/get.dart';
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
import 'package:shortzz/screen/live_stream/livestream_screen/host/livestream_host_screen.dart';
import 'package:shortzz/screen/live_stream/livestream_screen/livestream_screen_controller.dart';
import 'package:shortzz/screen/report_sheet/report_sheet.dart';

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

/// The Live tab, Snaps style: one live at a time, full screen, swipe up for
/// the next. The live on screen is the room itself — video, comments, gifts
/// and likes — joined as a viewer, with no separate screen to open; nobody
/// live shows a plain "no one is live" instead of stale cards.
///
/// Your own live is not joined here: its page offers to go back to it.
class LiveFeedScreenController extends BaseController
    with WidgetsBindingObserver {
  static const int liveTabIndex = DashboardScreenController.liveTab;

  /// The tab's room is registered under this tag, so a room screen opened
  /// over the tab (your own live) never gets it back from Get.put.
  static const String roomTag = 'live_feed_room';

  final RxList<Livestream> streams = <Livestream>[].obs;
  final RxInt currentIndex = 0.obs;
  final RxBool hasLoaded = false.obs;
  final PageController pageController = PageController();

  /// The room on the current page, once joined.
  final Rxn<LivestreamScreenController> room = Rxn<LivestreamScreenController>();

  /// While you co-host the room on screen, swiping would drop you out of it.
  final RxBool isCoHosting = false.obs;

  /// Rooms that ended while they were on screen.
  final RxSet<String> endedRooms = <String>{}.obs;

  /// Rooms whose video could not be reached; tapping tries again.
  final RxSet<String> failedRooms = <String>{}.obs;

  final AppUserCacheController appUserCacheController =
      Get.find<AppUserCacheController>();

  Timer? _refreshTimer;
  Timer? _routeWatchdog;
  Timer? _advanceTimer;
  Timer? _dropTimer;
  Timer? _settleTimer;
  Worker? _coHostWatch;

  // Which room should be joined, and which is. Every join and leave runs
  // through _ops, one at a time, so a fast swipe can never leave two rooms
  // half-joined.
  String? _wantedRoom;
  String? _roomId;
  Future<void> _ops = Future.value();

  bool _appResumed = true;
  bool _navigating = false;

  /// The route the tab is on (the dashboard's), named by the tab from its
  /// own context. Get.currentRoute when the tab was first built is not it
  /// when a tapped push opened the tab under another screen, and the tab
  /// then counted itself covered for good.
  String? dashboardRoute;

  /// A live to show once the tab has loaded: a "@name is now live" push
  /// tapped before the tab was first opened.
  static Livestream? _pending;

  Livestream? get currentStream =>
      currentIndex.value < streams.length ? streams[currentIndex.value] : null;

  DashboardScreenController? get _dashboard =>
      Get.isRegistered<DashboardScreenController>()
          ? Get.find<DashboardScreenController>()
          : null;

  bool get _tabSelected =>
      (_dashboard?.selectedPageIndex.value ?? liveTabIndex) == liveTabIndex;

  bool get _coveredByRoute =>
      dashboardRoute != null && Get.currentRoute != dashboardRoute;

  bool get _isVisible =>
      _tabSelected && _appResumed && !_navigating && !_coveredByRoute;

  static bool isMine(Livestream stream) =>
      stream.hostId == SessionManager.instance.getUserID();

  /// The joined room, if it is [roomId]'s.
  LivestreamScreenController? roomFor(String? roomId) {
    final LivestreamScreenController? live = room.value;
    return live != null && roomId != null && roomId == _roomId ? live : null;
  }

  /// Shows [stream] on the Live tab, for a tapped "@name is now live" push.
  static void open(Livestream stream) {
    if (Get.isRegistered<LiveFeedScreenController>()) {
      Get.find<LiveFeedScreenController>()._focus(stream);
    } else {
      _pending = stream;
    }
  }

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
    dashboardRoute ??= Get.currentRoute;
    fetchStreams();
    _onVisibilityChanged();
    // Notices any other screen opening over the tab (a profile, the
    // notifications, a chat) and leaves the room, which would otherwise go
    // on playing behind it.
    _routeWatchdog = Timer.periodic(
        const Duration(milliseconds: 500), (_) => _syncRoom(now: true));
  }

  @override
  void onClose() {
    WidgetsBinding.instance.removeObserver(this);
    _refreshTimer?.cancel();
    _routeWatchdog?.cancel();
    _advanceTimer?.cancel();
    _dropTimer?.cancel();
    _settleTimer?.cancel();
    _wantedRoom = null;
    _ops = _ops.then((_) => _leaveRoom());
    pageController.dispose();
    super.onClose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    // `inactive` is the notification shade or a system dialog over the app;
    // only leaving the app leaves the room.
    _appResumed = state == AppLifecycleState.resumed ||
        state == AppLifecycleState.inactive;
    _onVisibilityChanged();
  }

  void _onVisibilityChanged() {
    if (isClosed) return;
    if (_isVisible) {
      _refreshTimer ??= Timer.periodic(
          const Duration(seconds: 15), (_) => fetchStreams(silent: true));
    } else {
      _refreshTimer?.cancel();
      _refreshTimer = null;
    }
    _syncRoom(now: true);
  }

  Future<void> fetchStreams({bool silent = false}) async {
    if (!silent) isLoading.value = true;
    try {
      final fresh = await LivestreamService.instance.fetchLivestreams();
      if (isClosed) return;
      _assignHostUsers(fresh);
      _merge(fresh);
    } catch (e) {
      Loggers.error('fetchLivestreams failed: $e');
    } finally {
      isLoading.value = false;
      hasLoaded.value = true;
    }
    final Livestream? pending = _pending;
    if (pending != null && !isClosed) {
      _pending = null;
      _focus(pending);
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
    _syncRoom();
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

  /// Puts [stream] on the current page, moving it there if it is already
  /// listed further along.
  void _focus(Livestream stream) {
    final String? roomId = stream.roomID;
    if (roomId == null || roomId.isEmpty) return;
    endedRooms.remove(roomId);
    failedRooms.remove(roomId);
    _assignHostUsers([stream]);
    final List<Livestream> next =
        streams.where((other) => other.roomID != roomId).toList();
    // Without a pager yet (nothing was live), it starts on the first page.
    final int at = pageController.hasClients
        ? currentIndex.value.clamp(0, next.length)
        : 0;
    next.insert(at, stream);
    streams.assignAll(next);
    currentIndex.value = at;
    if (pageController.hasClients) pageController.jumpToPage(at);
    _syncRoom(now: true);
  }

  void onPageChanged(int index) {
    currentIndex.value = index;
    _advanceTimer?.cancel();
    // Coming back to a room that could not connect tries it again.
    failedRooms.clear();
    _syncRoom();
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
    _syncRoom(now: true);
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

  /// The room let go of itself: the live ended, or its video was lost.
  void _onRoomExit(String roomId, bool ended) {
    if (ended) {
      _onRoomEnded(roomId);
    } else {
      failedRooms.add(roomId);
      _syncRoom(now: true);
    }
  }

  void retry(String? roomId) {
    if (roomId == null) return;
    failedRooms.remove(roomId);
    _syncRoom(now: true);
  }

  void reportCurrentHost() {
    final Livestream? stream = currentStream;
    if (stream == null || isMine(stream)) return;
    if (!AuthGate.check()) return;
    Get.bottomSheet(ReportSheet(reportType: ReportType.user, id: stream.hostId),
        isScrollControlled: true);
  }

  // ---------------------------------------------------------------------
  // The room on screen
  // ---------------------------------------------------------------------

  /// Leaving happens straight away; joining waits for a swipe to settle, so
  /// a page dragged halfway and back keeps its room.
  void _syncRoom({bool now = false}) {
    if (isClosed) return;
    final Livestream? stream = currentStream;
    final String? roomId = stream?.roomID;
    final bool show = _isVisible &&
        stream != null &&
        roomId != null &&
        roomId.isNotEmpty &&
        !isMine(stream) &&
        !endedRooms.contains(roomId) &&
        !failedRooms.contains(roomId);
    final String? wanted = show ? roomId : null;
    if (wanted == _wantedRoom) return;
    _wantedRoom = wanted;
    _settleTimer?.cancel();
    if (wanted == null || now) {
      _queueReconcile();
    } else {
      _settleTimer =
          Timer(const Duration(milliseconds: 250), _queueReconcile);
    }
  }

  void _queueReconcile() {
    _ops = _ops.then((_) => _reconcile()).catchError((Object e) {
      Loggers.error('Live tab room: $e');
    });
  }

  Future<void> _reconcile() async {
    final String? wanted = _wantedRoom;
    if (wanted == _roomId) return;
    if (_roomId != null) await _leaveRoom();
    if (wanted != null && wanted == _wantedRoom && !isClosed) {
      _enterRoom(wanted);
    }
  }

  void _enterRoom(String roomId) {
    final Livestream? stream =
        streams.firstWhereOrNull((stream) => stream.roomID == roomId);
    if (stream == null) return;
    // Get.put hands back whatever is registered under the tag rather than
    // this new room.
    if (Get.isRegistered<LivestreamScreenController>(tag: roomTag)) {
      GetInstance()
          .delete<LivestreamScreenController>(tag: roomTag, force: true);
    }
    // Permanent: GetX ties an instance to whatever route is on top when it
    // is put, and deletes it when that route closes. A room joined while a
    // sheet is open (the next live, after one ended) would have died with
    // the sheet. The tab deletes it itself.
    final LivestreamScreenController live = Get.put(
        LivestreamScreenController(stream.obs, false,
            onEmbeddedExit: (ended) => _onRoomExit(roomId, ended)),
        tag: roomTag,
        permanent: true);
    _roomId = roomId;
    room.value = live;
    final int myId = SessionManager.instance.getUserID();
    _coHostWatch = ever<Livestream>(live.liveData, (data) {
      isCoHosting.value = data.coHostIds?.contains(myId) ?? false;
    });
    _prefetchNext();
  }

  Future<void> _leaveRoom() async {
    final LivestreamScreenController? live = room.value;
    room.value = null;
    _roomId = null;
    _coHostWatch?.dispose();
    _coHostWatch = null;
    isCoHosting.value = false;
    if (live == null) return;
    // Out of the room (on the server, and its video) before the next one
    // connects.
    await live.logoutRoom();
    if (Get.isRegistered<LivestreamScreenController>(tag: roomTag) &&
        identical(Get.find<LivestreamScreenController>(tag: roomTag), live)) {
      GetInstance()
          .delete<LivestreamScreenController>(tag: roomTag, force: true);
    }
  }

  /// While one live plays, the next one's token is fetched, so swiping to it
  /// skips that round trip. Only the token: holding a connection to the next
  /// room as well would double the LiveKit minutes every viewer uses.
  void _prefetchNext() {
    final int next = currentIndex.value + 1;
    if (next >= streams.length) return;
    final Livestream stream = streams[next];
    final String? roomId = stream.roomID;
    if (roomId == null ||
        roomId.isEmpty ||
        isMine(stream) ||
        endedRooms.contains(roomId)) {
      return;
    }
    LiveVideo.prefetchToken(roomId);
  }

  // ---------------------------------------------------------------------
  // Leaving the tab for your own live, or for going live
  // ---------------------------------------------------------------------

  Future<void> openMyLive(Livestream stream) async {
    await _openOver(() async {
      await Get.to(() => LivestreamHostScreen(isHost: true, livestream: stream));
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

  /// Leaves the room, opens a screen that needs the camera or a room of its
  /// own, and comes back once that screen has fully let go of it.
  Future<void> _openOver(Future<void> Function() open) async {
    if (_navigating) return;
    _navigating = true;
    _syncRoom(now: true);
    try {
      await _ops;
      await open();
    } finally {
      // A room screen leaves its room when it is disposed, which is after
      // its route has popped; joining again before then would race it.
      for (int i = 0;
          i < 30 && Get.isRegistered<LivestreamScreenController>();
          i++) {
        await Future.delayed(const Duration(milliseconds: 100));
      }
      _navigating = false;
      if (!isClosed) {
        await fetchStreams(silent: true);
        _syncRoom(now: true);
      }
    }
  }
}

import 'dart:async';

import 'package:get/get.dart';
import 'package:shortzz/common/controller/app_user_cache_controller.dart';
import 'package:shortzz/common/controller/base_controller.dart';
import 'package:shortzz/common/functions/auth_gate.dart';
import 'package:shortzz/common/extensions/list_extension.dart';
import 'package:shortzz/common/manager/logger.dart';
import 'package:shortzz/common/manager/session_manager.dart';
import 'package:shortzz/common/service/api/livestream_service.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/model/general/settings_model.dart';
import 'package:shortzz/model/livestream/app_user.dart';
import 'package:shortzz/model/livestream/livestream.dart';
import 'package:shortzz/model/user_model/user_model.dart';
import 'package:shortzz/screen/dashboard_screen/dashboard_screen_controller.dart';
import 'package:shortzz/screen/live_stream/create_live_stream_screen/create_live_stream_screen.dart';
import 'package:shortzz/screen/live_stream/livestream_screen/audience/live_stream_audience_screen.dart';
import 'package:shortzz/screen/live_stream/livestream_screen/host/livestream_host_screen.dart';

/// Live tab: real streams from the backend.
class LiveStreamSearchScreenController extends BaseController {
  RxList<Livestream> livestreamList = <Livestream>[].obs;
  RxList<Livestream> livestreamFilterList = <Livestream>[].obs;

  final appUserCacheController = Get.find<AppUserCacheController>();

  Setting? get setting => SessionManager.instance.getSettings();

  Timer? _refreshTimer;

  @override
  void onReady() {
    super.onReady();
    fetchLiveStreams();
    // The list is a lobby, not a room — a slow refresh keeps it current
    // without a realtime channel.
    _refreshTimer = Timer.periodic(
        const Duration(seconds: 15), (_) => fetchLiveStreams(silent: true));
  }

  @override
  void onClose() {
    super.onClose();
    _refreshTimer?.cancel();
  }

  Future<void> fetchLiveStreams({bool silent = false}) async {
    if (!silent) isLoading.value = true;
    try {
      final streams = await LivestreamService.instance.fetchLivestreams();
      final all = [...streams];
      all.sort((a, b) => (b.createdAt ?? 0).compareTo(a.createdAt ?? 0));
      livestreamList.value = all;
      _assignHostUsersToStreams();
      livestreamFilterList.value = List.from(all);
    } catch (e) {
      Loggers.error('fetchLivestreams failed: $e');
    } finally {
      isLoading.value = false;
    }
  }

  void _assignHostUsersToStreams() {
    final userMap = _userMapFromList(appUserCacheController.users);
    for (var stream in livestreamList) {
      stream.hostUser = userMap[stream.hostId];
      if (stream.hostUser == null && (stream.hostId ?? -1) != -1) {
        appUserCacheController.fetchUserIfNeeded(stream.hostId!);
      }
    }
  }

  Map<int, AppUser> _userMapFromList(List<AppUser> list) {
    return {
      for (var user in list)
        if (user.userId != null) user.userId!: user,
    };
  }

  void onLiveUserTap(Livestream stream) async {
    // Watching is open to everyone, matching the signed-out Live tab that got
    // them here. The in-stream write paths (comment, gift, like, co-host) each
    // gate individually in LivestreamScreenController, so a guest is prompted
    // to sign in at the point they try to interact rather than at the door.

    // The engine is built lazily (it is no longer created for every user at
    // dashboard startup), so make sure it exists before opening a room.
    await _ensureZegoEngine();

    User? myUser = SessionManager.instance.getUser();
    if (stream.hostId == myUser?.id) {
      Get.to(() => LivestreamHostScreen(isHost: true, livestream: stream));
    } else {
      Get.to(() => LiveStreamAudienceScreen(isHost: false, livestream: stream));
    }
  }

  onSearchChange(String value) {
    livestreamFilterList.value = livestreamList.search(value, (p0) {
      return p0.hostUser?.username ?? '';
    }, (p1) => p1.description ?? '');
  }

  /// The Zego engine is created on first use rather than at app startup, so
  /// every entry point into a room has to make sure it exists first.
  Future<void> _ensureZegoEngine() async {
    if (Get.isRegistered<DashboardScreenController>()) {
      await Get.find<DashboardScreenController>().ensureZegoEngine();
    }
  }

  Future<void> onGoLive() async {
    // Gate BEFORE navigating. Without this a guest walked all the way into
    // the camera preview (granting camera + mic permissions on the way) and
    // then hit a silent dead-end in CreateLiveStreamScreenController, which
    // just logged "User Not found" and returned. Broadcasting needs an
    // account, so ask for one at the button.
    if (!AuthGate.check()) return;

    await _ensureZegoEngine();

    User? myUser = SessionManager.instance.getUser();
    bool isExist =
        livestreamList.any((element) => element.hostId == myUser?.id);
    if (myUser?.isDummy == 1 && isExist) {
      return showSnackBar(LKey.yourProfileIsAlreadyInUseForDummyEtc.tr);
    }

    // A stale previous stream is ended server-side by createLivestream.
    Get.to(() => const CreateLiveStreamScreen());
  }
}

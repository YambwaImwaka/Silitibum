import 'dart:async';

import 'package:get/get.dart';
import 'package:shortzz/common/controller/app_user_cache_controller.dart';
import 'package:shortzz/common/controller/base_controller.dart';
import 'package:shortzz/common/functions/auth_gate.dart';
import 'package:shortzz/common/extensions/list_extension.dart';
import 'package:shortzz/common/extensions/user_extension.dart';
import 'package:shortzz/common/manager/logger.dart';
import 'package:shortzz/common/manager/session_manager.dart';
import 'package:shortzz/common/service/api/livestream_service.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/model/general/settings_model.dart';
import 'package:shortzz/model/livestream/app_user.dart';
import 'package:shortzz/model/livestream/livestream.dart';
import 'package:shortzz/model/user_model/user_model.dart';
import 'package:shortzz/screen/live_stream/create_live_stream_screen/create_live_stream_screen.dart';
import 'package:shortzz/screen/live_stream/livestream_screen/audience/live_stream_audience_screen.dart';
import 'package:shortzz/screen/live_stream/livestream_screen/host/livestream_host_screen.dart';

/// Live tab: real streams from the backend, dummy lives merged locally from
/// settings (they are just prerecorded links — no server rooms needed).
class LiveStreamSearchScreenController extends BaseController {
  RxList<Livestream> livestreamList = <Livestream>[].obs;
  RxList<Livestream> livestreamFilterList = <Livestream>[].obs;

  final appUserCacheController = Get.find<AppUserCacheController>();

  Setting? get setting => SessionManager.instance.getSettings();

  RxList<DummyLive> get dummyLives => (setting?.dummyLives ?? []).obs;

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
      final all = [...streams, ..._dummyStreams()];
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

  /// Admin-managed fake lives (prerecorded links) shown alongside real ones.
  List<Livestream> _dummyStreams() {
    if (setting?.liveDummyShow == 0) return [];
    final List<Livestream> streams = [];
    for (final dummy in dummyLives) {
      final User? dummyUser = dummy.user;
      if (dummyUser == null || dummy.status != 1) continue;
      appUserCacheController.addUser(dummyUser);
      streams.add(dummyUser.livestream(
          type: LivestreamType.dummy,
          time: DateTime.now().millisecondsSinceEpoch,
          dummyUserLink: dummy.link,
          isDummyLive: 1,
          description: dummy.title));
    }
    return streams;
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
    // Joining as audience writes viewer state and opens in-stream
    // comment/gift paths that all assume a user — one gate at the door.
    if (!AuthGate.check()) return;
    User? myUser = SessionManager.instance.getUser();
    if (stream.hostId == myUser?.id && stream.isDummyLive != 1) {
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

  Future<void> onGoLive() async {
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

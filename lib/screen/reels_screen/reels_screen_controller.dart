import 'dart:async';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:shortzz/common/controller/base_controller.dart';
import 'package:shortzz/common/extensions/string_extension.dart';
import 'package:shortzz/common/functions/auth_gate.dart';
import 'package:shortzz/common/functions/debounce_action.dart';
import 'package:shortzz/common/manager/logger.dart';
import 'package:shortzz/common/manager/session_manager.dart';
import 'package:shortzz/common/service/api/post_service.dart';
import 'package:shortzz/common/service/media/video_cache_service.dart';
import 'package:shortzz/model/post_story/comment/fetch_comment_model.dart';
import 'package:shortzz/model/post_story/post_model.dart';
import 'package:shortzz/screen/comment_sheet/helper/comment_helper.dart';
import 'package:shortzz/screen/dashboard_screen/dashboard_screen_controller.dart';
import 'package:shortzz/screen/profile_screen/profile_screen_controller.dart';
import 'package:shortzz/screen/profile_screen/widget/post_options_sheet.dart';
import 'package:shortzz/screen/reels_screen/reel/reel_page_controller.dart';
import 'package:shortzz/screen/reels_screen/widget/ad_slot.dart';
import 'package:shortzz/screen/reels_screen/widget/reel_page_type.dart';
import 'package:shortzz/screen/report_sheet/report_sheet.dart';
import 'package:video_player/video_player.dart';
import 'package:wakelock_plus/wakelock_plus.dart';

class ReelsScreenController extends BaseController {
  /// Player bookkeeping. Deliberately a *plain* map: as an RxMap every
  /// mutation notified the `Obx` in every mounted page, and a single swipe
  /// mutates it ~10 times (4 lookahead slots each marked initializing then
  /// initialized, plus disposals). That rebuilt all ~3 live ReelPage subtrees
  /// ~10 times per swipe — during the swipe animation. Per-page notification
  /// now lives in [_slotViews], so only the page whose controller actually
  /// changed rebuilds.
  final Map<int, ReelPlayerEntry> players = {};

  /// One observable per page index, holding the controller that page should
  /// render (null while uninitialized or after disposal).
  final Map<int, Rx<VideoPlayerController?>> _slotViews = {};

  /// Stable like-button [GlobalKey] per page index. These used to be built
  /// inline in the itemBuilder (`likeKey: GlobalKey()`), which minted a fresh
  /// key on every rebuild: that forces the subtree under it to be rebuilt
  /// from scratch, and it left the in-flight like animation holding a stale
  /// key whose `currentContext` was null, so the heart flew to the wrong
  /// place. Keyed by index (not reel id) because locally-uploading reels all
  /// share id -1 and duplicate GlobalKeys in one tree throw.
  final Map<int, GlobalKey> _likeKeys = {};

  static const String tag = "REEL";
  RxInt position = 0.obs;

  PageController pageController = PageController();

  RxList<Post> reels;
  Future<void> Function()? onFetchMoreData;

  final RxBool isRefreshing = false.obs;
  CommentHelper commentHelper = CommentHelper();
  bool isCurrentPageVisible = true;

  ReelPageType reelPageType;

  ReelsScreenController({required this.reels,
    required this.position,
    this.onFetchMoreData,
    required this.reelPageType});

  @override
  void onInit() {
    super.onInit();
    // pageController = PageController(initialPage: position.value);
    WakelockPlus.enable();
  }

  @override
  void onClose() {
    super.onClose();
    pageController.dispose();
    WakelockPlus.disable();
    disposeAllController();
  }

  /// Observable view of one page's controller. Pages watch this instead of the
  /// whole player map so a swipe only rebuilds the pages that actually changed.
  Rx<VideoPlayerController?> slotFor(int index) =>
      _slotViews.putIfAbsent(index, () => Rx<VideoPlayerController?>(null));

  GlobalKey likeKeyFor(int index) =>
      _likeKeys.putIfAbsent(index, () => GlobalKey());

  /// Pushes the current state of slot [index] out to whichever page is
  /// watching it. Replaces the old blanket `players.refresh()`.
  void _publishSlot(int index) {
    final entry = players[index];
    final controller =
        entry?.status == PlayerStatus.disposed ? null : entry?.controller;
    final slot = slotFor(index);
    if (slot.value != controller) slot.value = controller;
  }

  Future<void> initVideoPlayer() async {
    /// Initialize 1st video
    await _initializeControllerAtIndex(position.value);

    /// Play 1st video
    _playControllerAtIndex(position.value);

    /// Then the *next* videos — that is where the user is going, so they get
    /// the bandwidth first. The previous video (instant back-swipe) is the
    /// least likely to be needed, so it no longer blocks the lookahead.
    await _initializeControllerAtIndex(position.value + 1);
    await _initializeControllerAtIndex(position.value + 2);
    await _initializeControllerAtIndex(position.value - 1);

    _prefetchBeyondLookahead(position.value);
  }

  void onPageChanged(int index) {
    final isForward = index > position.value;
    position.value = index;

    if (isForward) _fetchMoreData(index);

    // Both directions want the same thing: stop what was playing, make sure
    // the window around `index` is warm, drop everything outside it.
    pauseAllPlayers(except: index);
    _disposeAllExcept(index);

    _initializeControllerAtIndex(index);
    _initializeControllerAtIndex(index + 1);
    _initializeControllerAtIndex(index + 2);
    _initializeControllerAtIndex(index - 1);

    _prefetchBeyondLookahead(index);

    _playControllerAtIndex(index);
  }

  Future<void> _fetchMoreData(int index) async {
    // Kick off the next page immediately — the old 1s artificial delay meant
    // fast swipers hit the end of the list and stared at a spinner.
    if (index >= reels.length - 3) {
      await onFetchMoreData?.call().then((value) {
        _initializeControllerAtIndex(position.value + 1);
        _initializeControllerAtIndex(position.value + 2);
      });
    }
  }

  /// Warms the disk cache for reels just *outside* the controller window.
  ///
  /// Prefetch used to run alongside `VideoPlayerController.networkUrl` for the
  /// very same url, so each reel was pulled down twice concurrently — once by
  /// ExoPlayer to play it, once by the cache manager to store it. With four
  /// controllers alive that is eight competing downloads, and the one the user
  /// is actually watching only got a fraction of the bandwidth, which is what
  /// made playback stall mid-reel. Now the two never overlap: indices inside
  /// the window stream, indices beyond it download, and by the time the user
  /// swipes that far the file is already local.
  void _prefetchBeyondLookahead(int index) {
    for (final i in [index + 3, index + 4]) {
      if (i < 0 || i >= reels.length) continue;
      if (players[i]?.controller != null) continue;
      final reel = reels[i];
      if (reel.id == -1) continue;
      // Sponsored slots have no video to warm.
      if (AdSlot.isAdSlot(reel)) continue;
      VideoCacheService.prefetch(reel.video?.addBaseURL() ?? '');
    }
  }

  void _disposeAllExcept(int index) {
    final validIndexes = {index - 1, index, index + 1, index + 2};

    final keys = players.keys.toList(); // 👈 COPY

    for (final i in keys) {
      if (!validIndexes.contains(i)) {
        _disposeControllerAtIndex(i);
        players.remove(i);
        _publishSlot(i);
      }
    }
  }

  void _disposeControllerAtIndex(int index) {
    ReelPlayerEntry? entry = players[index];
    if (entry == null) return;
    if (entry.status == PlayerStatus.disposed || entry.status == PlayerStatus.none) return;

    final controller = entry.controller;

    if (controller != null) {
      if (entry.listener != null) {
        controller.removeListener(entry.listener!);
      }
      controller.pause();
      controller.dispose();
    }

    entry.controller = null;
    entry.listener = null;
    entry.status = PlayerStatus.disposed;
    players[index] = entry;
    _publishSlot(index);

    Loggers.info("🗑 DISPOSED $index");
  }

  Future<void> _initializeControllerAtIndex(int index) async {
    if (index < 0 || index >= reels.length) return;

    // A sponsored slot renders a native ad view, not a video — never allocate a
    // player for it. Leaving the slot without an entry also means
    // _playControllerAtIndex finds nothing to play, which is what keeps ad
    // pages from firing _increaseViewsCount against the sentinel id.
    if (AdSlot.isAdSlot(reels[index])) return;

    /// 🔒 HARD GUARD (no race possible)
    if (players[index]?.status == PlayerStatus.initializing ||
        players[index]?.status == PlayerStatus.initialized) {
      return;
    }

    /// 🔒 Mark initializing IMMEDIATELY
    final entry = ReelPlayerEntry(status: PlayerStatus.initializing);
    players[index] = entry;
    try {
      late VideoPlayerController controller;

      final reel = reels[index];
      if (reel.id == -1) {
        controller = VideoPlayerController.file(
          File(reel.video ?? ''),
        );
      } else {
        // Prefers the HLS master playlist once transcoding has finished
        // (adaptive bitrate), falling back to the raw MP4 otherwise — see
        // Post.playbackVideoPath. VideoCacheService's disk cache only ever
        // holds MP4s (prefetch() below always targets `video`, never
        // `hlsPath`), so an HLS url simply misses the cache lookup and falls
        // through to networkUrl(), which is the correct behavior: ExoPlayer/
        // AVPlayer do their own internal HLS segment handling.
        final videoUrl = reel.playbackVideoPath?.addBaseURL() ?? '';
        // Play straight from disk when _prefetchBeyondLookahead already landed
        // the file (or this is a rewatch) — zero network cost. Otherwise
        // stream it. Note we deliberately do *not* also kick off a cache
        // download for this url here: doing both meant downloading the same
        // video twice at once and starving the playing stream.
        controller = await VideoCacheService.createController(videoUrl);
      }

      await controller.initialize();

      // While we awaited, this slot may have been disposed/removed (fast
      // swipes, pull-to-refresh, screen close). Storing the controller now
      // would orphan a live ExoPlayer instance — dispose it instead.
      if (players[index] != entry || entry.status != PlayerStatus.initializing) {
        controller.dispose();
        Loggers.info("🗑 DISPOSED stale init $index");
        return;
      }

      controller.setLooping(true);

      players[index] = ReelPlayerEntry(
        controller: controller,
        status: PlayerStatus.initialized,
      );
      _publishSlot(index);

      Loggers.info("🚀 INITIALIZED $index");

      if (index == position.value) {
        _playControllerAtIndex(index);
      }
    } catch (e) {
      Loggers.error("❌ INIT FAILED $index $e");

      _disposeControllerAtIndex(index);
    }
  }

  void _playControllerAtIndex(int index) {
    // Explicit rather than relying on the null-controller check below: this is
    // also what stops _increaseViewsCount POSTing the ad sentinel id.
    if (index >= 0 && index < reels.length && AdSlot.isAdSlot(reels[index])) {
      return;
    }

    final dashController = Get.find<DashboardScreenController>();
    if (reelPageType == ReelPageType.home && dashController.selectedPageIndex.value != 0) {
      return;
    }

    final entry = players[index];
    final controller = entry?.controller;

    if (controller == null) return;
    if (!controller.value.isInitialized) return;

    controller.play();

    DebounceAction.shared.call(milliseconds: 3000, () {
      _increaseViewsCount(reels[index]);
    });
    Loggers.info('🚀🚀🚀 PLAYING $index');
  }

  void _increaseViewsCount(Post reelData) {
    PostService.instance.increaseViewsCount(postId: reelData.id).then((value) {
      if (value.status == true) {
        if (Get.isRegistered<ReelController>(tag: reelData.id.toString())) {
          Get.find<ReelController>(tag: reelData.id.toString())
              .updateReelData(reel: reelData, isIncreaseCoin: true);
        }
      }
    });
  }

  void pauseAllPlayers({int? except}) {
    final keys = players.keys.toList(); // 👈 COPY
    for (var i in keys) {
      if (i == except) continue;
      _stopControllerAtIndex(i);
    }
  }

  void _stopControllerAtIndex(int index) {
    if (reels.length > index && index >= 0) {
      final controller = players[index]?.controller;
      if (controller == null || !controller.value.isInitialized) return;
      if (controller.value.isPlaying) controller.pause();

      // Only rewind a player that actually advanced. This ran unconditionally
      // on every player on every swipe, including the +1/+2 lookahead ones
      // sitting untouched at 0:00 — and seeking ExoPlayer flushes the preroll
      // buffer, throwing away exactly the head-start the lookahead existed to
      // build. That is what made the next swipe re-buffer.
      if (controller.value.position > Duration.zero) {
        controller.seekTo(Duration.zero);
      }
      Loggers.info('🚀🚀🚀 STOPPED $index');
    }
  }

  Future<void> disposeAllController() async {
    final entries = players.entries.toList(); // 👈 COPY
    for (var entry in entries) {
      final controller = entry.value.controller;
      final listener = entry.value.listener;
      if (listener != null) {
        controller?.removeListener(listener);
      }
      controller?.pause();
      await controller?.dispose();
      entry.value.controller = null;
    }
    players.clear();
    // Drop the controller reference any still-mounted page is holding, so
    // nothing paints a disposed player. The Rx objects themselves are kept:
    // a mounted Obx subscribes to the instance it read at build time, so
    // replacing them here (this also runs mid-session from handleRefresh)
    // would leave that page listening to an Rx nobody publishes to again.
    // They are a handful of bytes each and die with the controller.
    for (final slot in _slotViews.values) {
      slot.value = null;
    }
  }

  /// Handle refresh logic
  Future<void> handleRefresh(Future<void> Function()? onRefresh) async {
    if (isRefreshing.value) return;
    isRefreshing.value = true;

    await onRefresh?.call();
    await Future.delayed(const Duration(milliseconds: 200));
    if (reels.isNotEmpty) {
      position.value = 0;
      pageController.jumpToPage(0);
      await disposeAllController();
      initVideoPlayer();
    }

    isRefreshing.value = false;
    update();
  }

  void onReportTap() {
    if (!AuthGate.check()) return;
    Get.bottomSheet(ReportSheet(reportType: ReportType.post, id: reels[position.value].id?.toInt()),
        isScrollControlled: true);
  }

  void onUpdateComment(Comment comment, bool isReplyComment) {
    final post = reels.firstWhereOrNull((e) => e.id == comment.postId);
    if (post == null) {
      return Loggers.error('Post not found');
    }
    final controllerTag = post.id.toString();
    if (Get.isRegistered<ReelController>(tag: controllerTag)) {
      Get.find<ReelController>(tag: controllerTag)
          .reelData
          .update((val) => val?.updateCommentCount(1));
    }
  }

  void openPostOptionsSheet() {
    if (!AuthGate.check()) return;
    const tag = ProfileScreenController.tag;

    final controller = Get.isRegistered<ProfileScreenController>(tag: tag)
        ? Get.find<ProfileScreenController>(tag: tag)
        : Get.put(ProfileScreenController(SessionManager.instance.getUser().obs, (user) {}),
            tag: tag);

    Get.bottomSheet(
        PostOptionsSheet(
          controller: controller,
          onChanged: (type) {
            if (type == PublishType.goLive) {
              Future.delayed(
                const Duration(seconds: 1),
                () {
                  final controller = Get.find<DashboardScreenController>();
                  controller.onChanged(2);
                },
              );
            }
          },
        ),
        isScrollControlled: true);
  }

  onUpdateReelData(Post reel) {
    final index = reels.indexWhere((element) => element.id == reel.id);
    if (index != -1) {
      reels[index] = reel; // ✅ update field only
    }
  }
}

class ReelPlayerEntry {
  VideoPlayerController? controller;
  VoidCallback? listener;
  PlayerStatus status;

  ReelPlayerEntry({this.controller, this.listener, this.status = PlayerStatus.none});
}

enum PlayerStatus { none, initializing, initialized, disposed }

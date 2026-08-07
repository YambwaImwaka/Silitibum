import 'package:get/get.dart';
import 'package:shortzz/common/controller/base_controller.dart';
import 'package:shortzz/common/manager/logger.dart';
import 'package:shortzz/common/service/api/post_service.dart';
import 'package:shortzz/model/post_story/post/explore_page_model.dart';
import 'package:shortzz/model/post_story/post_model.dart';
import 'package:shortzz/screen/hashtag_screen/hashtag_screen.dart';
import 'package:shortzz/screen/post_screen/single_post_screen.dart';
import 'package:shortzz/screen/reels_screen/reels_screen.dart';
import 'package:shortzz/screen/reels_screen/widget/reel_page_type.dart';
import 'package:shortzz/screen/scan_qr_code_screen/scan_qr_code_screen.dart';
import 'package:shortzz/screen/video_player_screen/video_player_screen.dart';

class ExploreScreenController extends BaseController {
  Rx<ExplorePageData?> explorePageData = Rx(null);

  /// The full ranked trending list. The hashtag rails below it are a browsing
  /// aid; this is the actual "trending content" the Explore tab is meant to
  /// show, and unlike the rails it paginates.
  RxList<Post> trendingPosts = <Post>[].obs;
  RxBool isTrendingLoading = false.obs;
  RxBool hasMoreTrending = true.obs;
  bool _isFetchingTrending = false;

  @override
  void onInit() {
    super.onInit();
    fetchExplorePageData();
    fetchTrendingPosts(refresh: true);
  }

  Future<void> fetchExplorePageData() async {
    isLoading.value = true;
    try {
      explorePageData.value = await PostService.instance.fetchExplorePageData();
    } catch (e) {
      // A failed fetch must never leave the loader stuck.
      Loggers.error('fetchExplorePageData failed: $e');
    } finally {
      isLoading.value = false;
    }
  }

  Future<void> fetchTrendingPosts({bool refresh = false}) async {
    if (_isFetchingTrending) return;
    if (!refresh && !hasMoreTrending.value) return;
    _isFetchingTrending = true;
    isTrendingLoading.value = true;
    try {
      final items = await PostService.instance
          .fetchTrendingPosts(lastItemId: refresh ? null : trendingPosts.lastOrNull?.id?.toInt());
      if (refresh) trendingPosts.clear();
      trendingPosts.addAll(items);
      hasMoreTrending.value = items.isNotEmpty;
    } catch (e) {
      Loggers.error('fetchTrendingPosts failed: $e');
    } finally {
      isTrendingLoading.value = false;
      _isFetchingTrending = false;
    }
  }

  Future<void> onRefresh() async {
    await Future.wait([
      fetchExplorePageData(),
      fetchTrendingPosts(refresh: true),
    ]);
  }

  void onExploreTap(String? hashtag) {
    Get.to(() => HashtagScreen(hashtag: hashtag ?? '', index: 0),
        preventDuplicates: false);
  }

  void onPostTap(Post post) {
    switch (post.postType) {
      case PostType.reel:
        Get.to(() => ReelsScreen(reels: [post].obs, position: 0, pageType: ReelPageType.search));
        break;
      case PostType.video:
        // Long-form video gets the landscape player; only Snaps use the
        // vertical full-bleed layout.
        Get.to(() => VideoPlayerScreen(post: post));
        break;
      case PostType.image:
        Get.to(() => SinglePostScreen(post: post, isFromNotification: false));
        break;
      case PostType.text:
        break;
      case PostType.none:
        Loggers.error('Post Type none');
        break;
    }
  }

  void onScanQrCode() {
    Get.to(() => const ScanQrCodeScreen());
  }
}

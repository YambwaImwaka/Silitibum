import 'package:figma_squircle_updated/figma_squircle.dart';
import 'package:flutter/material.dart';
import 'package:flutter_staggered_grid_view/flutter_staggered_grid_view.dart';
import 'package:get/get.dart';
import 'package:shortzz/common/extensions/common_extension.dart';
import 'package:shortzz/common/extensions/string_extension.dart';
import 'package:shortzz/common/service/api/post_service.dart';
import 'package:shortzz/common/widget/custom_back_button.dart';
import 'package:shortzz/common/widget/custom_image.dart';
import 'package:shortzz/common/widget/gradient_text.dart';
import 'package:shortzz/common/widget/loader_widget.dart';
import 'package:shortzz/common/widget/my_refresh_indicator.dart';
import 'package:shortzz/common/widget/no_data_widget.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/model/post_story/hashtag_model.dart';
import 'package:shortzz/model/post_story/post/explore_page_model.dart';
import 'package:shortzz/model/post_story/post_model.dart';
import 'package:shortzz/screen/explore_screen/explore_screen_controller.dart';
import 'package:shortzz/screen/search_screen/search_screen.dart';
import 'package:shortzz/utilities/asset_res.dart';
import 'package:shortzz/utilities/style_res.dart';
import 'package:shortzz/utilities/text_style_custom.dart';
import 'package:shortzz/utilities/theme_res.dart';

/// Explore as its own screen, opened from the search icon on the main tabs'
/// top bars (it used to be a bottom-bar tab).
class ExplorePage extends StatelessWidget {
  const ExplorePage({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: scaffoldBackgroundColor(context),
      body: const ExploreScreen(showBack: true),
    );
  }
}

class ExploreScreen extends StatelessWidget {
  final bool showBack;

  const ExploreScreen({super.key, this.showBack = false});

  @override
  Widget build(BuildContext context) {
    final controller = Get.put(ExploreScreenController());

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SearchScreenTopView(controller: controller, showBack: showBack),
        Expanded(
          child: Obx(() {
            final isLoading = controller.isLoading.value;
            final exploreData = controller.explorePageData.value;
            final hasData = exploreData?.highPostHashtags?.isNotEmpty ?? false;

            final hasTrending = controller.trendingPosts.isNotEmpty;

            return MyRefreshIndicator(
              onRefresh: controller.onRefresh,
              child: isLoading && exploreData == null && !hasTrending
                  ? const LoaderWidget()
                  : NoDataView(
                      showShow: !isLoading && !hasData && !hasTrending,
                      title: LKey.searchPageEmptyTitle.tr,
                      description: LKey.searchPageEmptyDescription.tr,
                      child: CustomScrollView(
                        slivers: [
                          // The real trending feed, ranked and paginated. The
                          // hashtag rails below stay as a browsing aid.
                          if (hasTrending) ...[
                            SliverToBoxAdapter(
                                child: _TrendingHeader(
                                    title: LKey.trending.tr)),
                            TrendingPostGrid(controller: controller),
                          ],
                          SliverToBoxAdapter(
                            child: SearchScreenGridView(
                                postList: exploreData?.highPostHashtags ?? [],
                                controller: controller),
                          ),
                        ],
                      ),
                    ),
            );
          }),
        ),
      ],
    );
  }
}

class _TrendingHeader extends StatelessWidget {
  const _TrendingHeader({required this.title});

  final String title;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 14, 12, 8),
      child: Text(
        title.toUpperCase(),
        style: TextStyleCustom.unboundedBold700(
            fontSize: 14, color: textDarkGrey(context)),
      ),
    );
  }
}

/// The ranked trending grid. Paginates as the user scrolls, which the hashtag
/// rails never did — they were capped at five tiles each with no load-more.
class TrendingPostGrid extends StatelessWidget {
  const TrendingPostGrid({super.key, required this.controller});

  final ExploreScreenController controller;

  @override
  Widget build(BuildContext context) {
    return Obx(() {
      final posts = controller.trendingPosts;
      return SliverGrid(
        gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
          crossAxisCount: 3,
          crossAxisSpacing: 2,
          mainAxisSpacing: 2,
          childAspectRatio: 0.66,
        ),
        delegate: SliverChildBuilderDelegate(
          (context, index) {
            // Fetch the next page a little before the end so scrolling stays
            // continuous rather than pausing at the boundary.
            if (index >= posts.length - 6) {
              controller.fetchTrendingPosts();
            }
            final post = posts[index];
            return InkWell(
              onTap: () => controller.onPostTap(post),
              child: CustomImage(
                size: const Size(double.infinity, double.infinity),
                image: post.getThumbnail.addBaseURL(),
                radius: 0,
                fit: BoxFit.cover,
                isShowPlaceHolder: true,
              ),
            );
          },
          childCount: posts.length,
        ),
      );
    });
  }
}

class SearchScreenTopView extends StatelessWidget {
  final ExploreScreenController controller;
  final bool showBack;

  const SearchScreenTopView(
      {super.key, required this.controller, this.showBack = false});

  @override
  Widget build(BuildContext context) {
    return Container(
      color: scaffoldBackgroundColor(context),
      padding: const EdgeInsets.symmetric(vertical: 10),
      child: Column(
        children: [
          _buildSearchBar(context),
          _buildHashtagList(),
        ],
      ),
    );
  }

  Widget _buildSearchBar(BuildContext context) {
    return SafeArea(
      bottom: false,
      child: Row(
        children: [
          if (showBack)
            CustomBackButton(
                color: textDarkGrey(context),
                height: 26,
                width: 26,
                padding: const EdgeInsets.only(left: 12)),
          Expanded(
            child: InkWell(
              onTap: () => Get.to(() => const SearchScreen()),
              child: Container(
                height: 35,
                margin: const EdgeInsets.symmetric(horizontal: 15),
                padding: const EdgeInsets.symmetric(horizontal: 10),
                decoration: ShapeDecoration(
                  shape: SmoothRectangleBorder(
                    borderRadius: SmoothBorderRadius(cornerRadius: 7),
                    side: BorderSide(color: bgGrey(context)),
                  ),
                  color: bgMediumGrey(context),
                ),
                alignment: AlignmentDirectional.centerStart,
                child: Text(
                  LKey.searchHere.tr,
                  style: TextStyleCustom.outFitLight300(
                    fontSize: 15,
                    color: textLightGrey(context),
                  ),
                ),
              ),
            ),
          ),
          InkWell(
            onTap: controller.onScanQrCode,
            child: Image.asset(AssetRes.icQrCode, height: 26, width: 26),
          ),
          const SizedBox(width: 8),
        ],
      ),
    );
  }

  Widget _buildHashtagList() {
    return Obx(
      () => SearchScreenHashTagView(
        hashtags: controller.explorePageData.value?.hashtags ?? [],
        controller: controller,
      ),
    );
  }
}

class SearchScreenHashTagView extends StatelessWidget {
  final List<Hashtag> hashtags;
  final ExploreScreenController controller;

  const SearchScreenHashTagView({
    super.key,
    required this.hashtags,
    required this.controller,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      height: 35,
      margin: const EdgeInsets.only(top: 8),
      child: ListView.builder(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: 10),
        itemCount: hashtags.length,
        itemBuilder: (context, index) =>
            _buildHashtagItem(context, hashtags[index]),
      ),
    );
  }

  Widget _buildHashtagItem(BuildContext context, Hashtag hashtag) {
    return InkWell(
      onTap: () => controller.onExploreTap(hashtag.hashtag ?? ''),
      child: FittedBox(
        child: Container(
          height: 35,
          margin: const EdgeInsets.symmetric(horizontal: 3.5),
          padding: const EdgeInsets.symmetric(horizontal: 10),
          decoration: ShapeDecoration(
            shape: SmoothRectangleBorder(
              borderRadius:
                  SmoothBorderRadius(cornerRadius: 7, cornerSmoothing: 1),
              side: BorderSide(color: bgGrey(context)),
            ),
            color: bgMediumGrey(context),
          ),
          alignment: Alignment.center,
          child: Text(
            '#${hashtag.hashtag}',
            style: TextStyleCustom.outFitRegular400(
              color: textLightGrey(context),
              fontSize: 15,
            ),
          ),
        ),
      ),
    );
  }
}

class SearchScreenGridView extends StatelessWidget {
  final List<HighPostHashtags> postList;
  final ExploreScreenController controller;

  const SearchScreenGridView({
    super.key,
    required this.postList,
    required this.controller,
  });

  @override
  Widget build(BuildContext context) {
    return ListView.builder(
      itemCount: postList.length,
      padding: EdgeInsets.zero,
      itemBuilder: (context, index) {
        final highPostHashtags = postList[index];
        final posts = _preparePostList(highPostHashtags);
        if (postList.isEmpty) {
          return const SizedBox();
        }

        return Column(
          children: [
            if (posts.isNotEmpty) _buildHashtagHeader(context, highPostHashtags),
            _buildPostGrid(context, posts),
          ],
        );
      },
    );
  }

  List<Post> _preparePostList(HighPostHashtags highPostHashtags) {
    final posts = List<Post>.from(highPostHashtags.postList ?? []);

    if (posts.length >= 5) {
      final reelPostIndex =
          posts.indexWhere((p) => p.postType == PostType.reel);
      if (reelPostIndex != -1) {
        final reelPost = posts.removeAt(reelPostIndex);
        posts.insert(2, reelPost);
      }
    }
    return posts;
  }

  Widget _buildHashtagHeader(
      BuildContext context, HighPostHashtags highPostHashtags) {
    return Padding(
      padding:
          const EdgeInsets.only(left: 10.0, right: 10, top: 12, bottom: 12),
      child: Row(
        children: [
          _buildHashtagIcon(context),
          const SizedBox(width: 10),
          _buildHashtagInfo(highPostHashtags, context),
          _buildExploreButton(highPostHashtags, context),
        ],
      ),
    );
  }

  Widget _buildHashtagIcon(BuildContext context) {
    return Container(
      height: 38,
      width: 38,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        border: Border.all(
          color: themeAccentSolid(context).withValues(alpha: .2),
          width: 1.5,
        ),
      ),
      child: GradientText(
        '#',
        gradient: StyleRes.themeGradient,
        style: TextStyleCustom.outFitMedium500(fontSize: 22),
      ),
    );
  }

  Widget _buildHashtagInfo(
      HighPostHashtags highPostHashtags, BuildContext context) {
    return Expanded(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            highPostHashtags.hashtag ?? '',
            style: TextStyleCustom.unboundedSemiBold600(
              color: textDarkGrey(context),
            ),
          ),
          Text(
            '${(highPostHashtags.postList?.length ?? 0).numberFormat} ${LKey.posts.tr}',
            style: TextStyleCustom.outFitLight300(
              color: textLightGrey(context),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildExploreButton(
      HighPostHashtags highPostHashtags, BuildContext context) {
    return InkWell(
      onTap: () => controller.onExploreTap(highPostHashtags.hashtag),
      child: Row(
        children: [
          Text(
            LKey.explore.tr,
            style: TextStyleCustom.outFitLight300(
              color: textLightGrey(context),
            ),
          ),
          Image.asset(
            AssetRes.icRightArrow,
            color: textLightGrey(context),
            height: 20,
          ),
        ],
      ),
    );
  }

  Widget _buildPostGrid(BuildContext context, List<Post> posts) {
    return GridView.builder(
      primary: false,
      shrinkWrap: true,
      itemCount: posts.length >= 5 ? 5 : posts.length,
      padding: EdgeInsets.zero,
      gridDelegate: SliverQuiltedGridDelegate(
          crossAxisCount: 3,
          mainAxisSpacing: 1.5,
          crossAxisSpacing: 1.5,
          repeatPattern: QuiltedGridRepeatPattern.inverted,
          pattern: _getGridPattern(posts.length)),
      itemBuilder: (context, index) => _buildPostItem(context, posts[index]),
    );
  }

  List<QuiltedGridTile> _getGridPattern(int postCount) {
    return [
      const QuiltedGridTile(1, 1),
      const QuiltedGridTile(1, 1),
      postCount <= 4
          ? const QuiltedGridTile(1, 1)
          : const QuiltedGridTile(2, 1),
      const QuiltedGridTile(1, 1),
      const QuiltedGridTile(1, 1),
    ];
  }

  Widget _buildPostItem(BuildContext context, Post post) {
    final image =
        (post.postType == PostType.image && (post.images?.isNotEmpty ?? false)
                ? post.images!.first.image
                : post.thumbnail)
            ?.addBaseURL();

    return InkWell(
      onTap: () => controller.onPostTap(post),
      child: CustomImage(
        size: const Size(double.infinity, double.infinity),
        radius: 0,
        image: image,
        isShowPlaceHolder: true,
      ),
    );
  }
}

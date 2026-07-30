import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:shortzz/common/widget/load_more_widget.dart';
import 'package:shortzz/common/widget/loader_widget.dart';
import 'package:shortzz/common/widget/no_data_widget.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/model/post_story/post_model.dart';
import 'package:shortzz/screen/post_screen/post_card.dart';

class PostList extends StatefulWidget {
  final RxList<Post> posts;
  final RxBool isLoading;
  final Future<void> Function()? onFetchMoreData;
  final bool shrinkWrap;
  // Renders as slivers (SliverList) for use directly inside a
  // CustomScrollView, instead of a boxed ListView.builder. Use this — not
  // shrinkWrap — when this list sits alongside other scrolling content
  // (e.g. a story rail) under one shared scroll: shrinkWrap forces the
  // ListView to size itself to the full content height, which defeats its
  // viewport culling and keeps every item built and resident as the list
  // grows via pagination. A real sliver keeps only visible (+ cache extent)
  // items alive no matter how long the list gets.
  final bool asSliver;
  final bool shouldShowPinOption;
  final bool isMe;
  final bool showNoData;

  const PostList({
    super.key,
    required this.posts,
    required this.isLoading,
    this.onFetchMoreData,
    this.shrinkWrap = false,
    this.asSliver = false,
    this.shouldShowPinOption = false,
    this.isMe = false,
    this.showNoData = true,
  });

  @override
  State<PostList> createState() => _PostListState();
}

class _PostListState extends State<PostList> {
  RxList<Post> get posts => widget.posts;

  RxBool get isLoading => widget.isLoading;

  bool get asSliver => widget.asSliver;

  /// Stable like-button [GlobalKey] per row.
  ///
  /// This used to be `likeKey: GlobalKey()` inline in the item builder, which
  /// minted a brand new key every time the surrounding Obx fired — and it
  /// fires for every like, every pagination append, every refresh. A changed
  /// GlobalKey forces Flutter to tear the whole subtree down and re-inflate it
  /// instead of updating it in place, so each rebuild threw away every visible
  /// PostCard along with its images (they visibly re-fetch and flash) and left
  /// any in-flight like animation holding a stale key whose `currentContext`
  /// is null, sending the heart to the wrong spot. The reels list already hit
  /// this and fixed it the same way — see ReelsScreenController.likeKeyFor.
  ///
  /// Keyed by index rather than post id: posts still uploading share id -1,
  /// and two identical GlobalKeys in one tree throw.
  final Map<int, GlobalKey> _likeKeys = {};

  GlobalKey _likeKeyFor(int index) =>
      _likeKeys.putIfAbsent(index, () => GlobalKey());

  @override
  Widget build(BuildContext context) {
    return Obx(() {
      if (isLoading.value && posts.isEmpty) {
        return asSliver
            ? const SliverToBoxAdapter(child: LoaderWidget())
            : const LoaderWidget();
      }

      if (!isLoading.value && posts.isEmpty) {
        final empty = widget.showNoData ? _buildNoDataView() : const SizedBox();
        return asSliver ? SliverToBoxAdapter(child: empty) : empty;
      }

      if (asSliver) {
        return SliverPadding(
          padding: EdgeInsets.only(bottom: AppBar().preferredSize.height / 2),
          sliver: SliverList.builder(
            itemCount: posts.length,
            itemBuilder: (context, index) => _buildPostCard(posts[index], index),
          ),
        );
      }

      return LoadMoreWidget(
        loadMore: widget.onFetchMoreData ?? () async {},
        child: ListView.builder(
          itemCount: posts.length,
          primary: !widget.shrinkWrap,
          shrinkWrap: widget.shrinkWrap,
          padding: EdgeInsets.only(bottom: AppBar().preferredSize.height / 2),
          itemBuilder: (context, index) {
            final post = posts[index];
            return _buildPostCard(post, index);
          },
        ),
      );
    });
  }

  Widget _buildNoDataView() {
    return Stack(
      children: [
        NoDataView(
          title: widget.isMe ? LKey.noMyPostsTitle.tr : LKey.noUserPostsTitle.tr,
          description: widget.isMe
              ? LKey.noMyPostsDescription.tr
              : LKey.noUserPostsDescription.tr,
          showShow: !isLoading.value && posts.isEmpty,
        ),
        SingleChildScrollView(
            child: Container(
                height: MediaQuery.of(Get.context!).size.height,
                width: MediaQuery.of(Get.context!).size.width,
                color: Colors.transparent)),
      ],
    );
  }

  Widget _buildPostCard(Post post, int index) {
    return PostCard(
        post: post,
        shouldShowPinOption: widget.shouldShowPinOption,
        likeKey: _likeKeyFor(index));
  }
}

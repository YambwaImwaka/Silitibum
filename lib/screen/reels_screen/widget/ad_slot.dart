import 'package:shortzz/common/service/api/post_service.dart';
import 'package:shortzz/model/post_story/post_model.dart';

/// Marks a position in the Snaps feed that renders an ad instead of a Snap.
///
/// Deliberately a sentinel [Post] rather than a `FeedItem = Post | Ad` wrapper.
/// The codebase already uses this pattern — a locally-uploading Snap is a Post
/// with `id == -1` — and every list that feeds ReelsScreen is a `RxList<Post>`
/// (hashtag Snaps, profile Snaps, single-post deep links, chat-shared Snaps). A
/// wrapper type would have to ripple through all of those, while ads are only
/// ever inserted in one place: HomeScreenController.addResponseData.
class AdSlot {
  AdSlot._();

  /// Distinct from the -1 local-upload sentinel so the two never collide.
  static const int postId = -2;

  static bool isAdSlot(Post? post) => post?.id == postId;

  static Post build() => Post(id: postId, postType: PostType.none);
}

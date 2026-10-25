import 'package:firebase_crashlytics/firebase_crashlytics.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:shortzz/common/controller/base_controller.dart';
import 'package:shortzz/common/manager/logger.dart';
import 'package:shortzz/common/manager/session_manager.dart';
import 'package:shortzz/common/service/api/notification_service.dart';
import 'package:shortzz/common/service/api/post_service.dart';
import 'package:shortzz/common/service/navigation/navigate_with_controller.dart';
import 'package:shortzz/model/misc/activity_notification_model.dart';
import 'package:shortzz/model/misc/admin_notification_model.dart';
import 'package:shortzz/model/post_story/post_by_id.dart';
import 'package:shortzz/model/post_story/post_model.dart';
import 'package:shortzz/model/user_model/user_model.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/screen/notification_screen/widget/activity_notification_page.dart';
import 'package:shortzz/screen/post_screen/single_post_screen.dart';
import 'package:shortzz/screen/reels_screen/reels_screen.dart';
import 'package:shortzz/screen/reels_screen/widget/reel_page_type.dart';

class NotificationScreenController extends BaseController {
  RxInt selectedTabIndex = RxInt(0);

  /// The newest activity seen before this screen opened (the first fetch
  /// moves it on); anything newer is shown as new.
  final int _seenBeforeOpen = SessionManager.instance.lastSeenActivityId;

  /// Where the next page starts. Kept apart from the list because rows the
  /// app cannot describe are left out of it.
  int? _activityCursor;

  bool isUnread(ActivityNotification item) => (item.id ?? 0) > _seenBeforeOpen;

  RxList<AdminNotificationData> adminNotifications =
      <AdminNotificationData>[].obs;
  RxList<ActivityNotification> activityNotifications =
      <ActivityNotification>[].obs;

  RxBool isActivityNotification = RxBool(false);
  RxBool isAdminNotification = RxBool(false);

  PageController pageController = PageController();

  @override
  void onInit() {
    iniData();
    super.onInit();
  }

  onTabChange(int index) {
    selectedTabIndex.value = index;
  }

  void iniData() {
    fetchActivityNotifications();
    fetchAdminNotification();
  }

  /// Set when a fetch fails so the screen can offer a retry. Without this a
  /// failed request left the loading flag stuck true and the screen showed a
  /// spinner forever, with the re-entrancy guard below making retry
  /// impossible.
  RxnString activityError = RxnString();
  RxnString adminError = RxnString();

  Future<void> fetchAdminNotification() async {
    if (isAdminNotification.value) return;
    isAdminNotification.value = true;
    try {
      List<AdminNotificationData> items = await NotificationService.instance
          .fetchAdminNotifications(
              lastItemId: adminNotifications.lastOrNull?.id);
      if (items.isNotEmpty) {
        adminNotifications.addAll(items);
      }
      adminError.value = null;
    } catch (e, st) {
      Loggers.error('fetchAdminNotifications failed: $e');
      FirebaseCrashlytics.instance.recordError(e, st, fatal: false);
      if (adminNotifications.isEmpty) {
        adminError.value = LKey.somethingWentWrong.tr;
      }
    } finally {
      // Must clear in a finally: the await above can throw (a single stale
      // notification row used to 500 the whole endpoint), and the early
      // return at the top of this method then made the state unrecoverable.
      isAdminNotification.value = false;
    }
  }

  Future<void> fetchActivityNotifications() async {
    if (isActivityNotification.value) return;
    isActivityNotification.value = true;
    try {
      final bool firstPage = _activityCursor == null;
      List<ActivityNotification> items = await NotificationService.instance
          .fetchActivityNotifications(lastItemId: _activityCursor);
      if (items.isNotEmpty) {
        _activityCursor = items.last.id ?? _activityCursor;
        // A row that would read as just a name, with nothing to open, is
        // left out (a type this app version does not know).
        activityNotifications.addAll(
            items.where((item) => item.type != ActivityNotifyType.none));
      }
      // The newest are on the first page: seeing it clears the bell's badge.
      if (firstPage && items.isNotEmpty) {
        SessionManager.instance.setLastSeenActivityId(items.first.id ?? 0);
        SessionManager.instance.setUnreadActivity(0);
      }
      activityError.value = null;
    } catch (e, st) {
      Loggers.error('fetchActivityNotifications failed: $e');
      FirebaseCrashlytics.instance.recordError(e, st, fatal: false);
      if (activityNotifications.isEmpty) {
        activityError.value = LKey.somethingWentWrong.tr;
      }
    } finally {
      isActivityNotification.value = false;
    }
  }

  Future<void> retryActivityNotifications() async {
    activityError.value = null;
    await fetchActivityNotifications();
  }

  Future<void> retryAdminNotifications() async {
    adminError.value = null;
    await fetchAdminNotification();
  }


  void onPostTap(ActivityNotification? data) async {
    Post? post = data?.data?.post;
    int? commentId = data?.data?.comment?.id;
    int? replyCommentId = data?.data?.reply?.id;

    if (post == null || post.id == null) return;

    final PostByIdModel? result = await withLoader(() =>
        PostService.instance.fetchPostById(
            postId: post.id!, commentId: commentId, replyId: replyCommentId));
    if (result == null) return;

    if (result.status != true || result.data == null) {
      showSnackBar(result.message);
      return;
    }
    final Post? fetchedPost = result.data?.post;
    if (fetchedPost == null) return;
    final postType = post.postType;

    if (postType == PostType.reel) {
      Get.to(() => ReelsScreen(
            reels: [fetchedPost].obs,
        position: 0,
        postByIdData: result.data,
            pageType: ReelPageType.notification,
          ));
    } else if ([PostType.image, PostType.video, PostType.text]
        .contains(postType)) {
      Get.to(() => SinglePostScreen(
          post: fetchedPost,
          postByIdData: result.data,
          isFromNotification: true));
    }
  }

  /// The whole row opens what it is about: the post (at the comment, for
  /// comments and replies), the follower's profile, or for a gift the video
  /// it was sent on — else the person who sent it.
  void onNotificationTap(ActivityNotification data) {
    switch (data.type) {
      case ActivityNotifyType.notifyFollowUser:
        onUserTap(data.fromUser);
      case ActivityNotifyType.notifyGiftUser:
        if (data.data?.post?.id != null) {
          onPostTap(data);
        } else {
          onUserTap(data.fromUser);
        }
      case ActivityNotifyType.notifyLikePost:
      case ActivityNotifyType.notifyCommentPost:
      case ActivityNotifyType.notifyMentionPost:
      case ActivityNotifyType.notifyMentionComment:
      case ActivityNotifyType.notifyReplyComment:
      case ActivityNotifyType.notifyMentionReply:
      case ActivityNotifyType.notifyNewPost:
        onPostTap(data);
      case ActivityNotifyType.none:
        break;
    }
  }

  void onUserTap(User? user) async {
    NavigationService.shared.openProfileScreen(user);
  }
}

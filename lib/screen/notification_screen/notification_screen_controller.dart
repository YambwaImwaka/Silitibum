import 'package:firebase_crashlytics/firebase_crashlytics.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:shortzz/common/controller/base_controller.dart';
import 'package:shortzz/common/manager/logger.dart';
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
      List<ActivityNotification> items = await NotificationService.instance
          .fetchActivityNotifications(
              lastItemId: activityNotifications.lastOrNull?.id);
      if (items.isNotEmpty) {
        activityNotifications.addAll(items);
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

  void onDescriptionTap(ActivityNotification data) {
    if ([
      ActivityNotifyType.notifyLikePost,
      ActivityNotifyType.notifyCommentPost,
      ActivityNotifyType.notifyMentionPost,
      ActivityNotifyType.notifyMentionComment,
      ActivityNotifyType.notifyReplyComment,
      ActivityNotifyType.notifyMentionReply,
    ].contains(data.type)) {
      onPostTap(data);
    } else if (ActivityNotifyType.notifyFollowUser == data.type) {
      onUserTap(data.fromUser);
    }
  }

  void onUserTap(User? user) async {
    NavigationService.shared.openProfileScreen(user);
  }
}

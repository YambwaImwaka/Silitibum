import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:shortzz/common/controller/ads_controller.dart';
import 'package:shortzz/common/controller/app_user_cache_controller.dart';
import 'package:shortzz/common/controller/base_controller.dart';
import 'package:shortzz/common/functions/auth_gate.dart';
import 'package:shortzz/common/manager/firebase_notification_manager.dart';
import 'package:shortzz/common/manager/logger.dart';
import 'package:shortzz/common/manager/session_manager.dart';
import 'package:shortzz/common/service/api/chat_service.dart';
import 'package:shortzz/common/service/api/notification_service.dart';
import 'package:shortzz/common/service/api/user_service.dart';
import 'package:shortzz/common/service/realtime/realtime_service.dart';
import 'package:shortzz/common/service/subscription/subscription_manager.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/model/user_model/user_model.dart';
import 'package:shortzz/screen/camera_screen/camera_screen.dart';
import 'package:shortzz/screen/feed_screen/feed_screen_controller.dart';
import 'package:shortzz/screen/gif_sheet/gif_sheet_controller.dart';
import 'package:shortzz/screen/profile_screen/profile_screen_controller.dart';
import 'package:shortzz/screen/profile_screen/widget/post_options_sheet.dart';

class DashboardScreenController extends BaseController with GetSingleTickerProviderStateMixin {
  // The bottom bar's tabs; creating sits between Feed and Live but is a
  // sheet, not a tab. Explore and Messages left the bar in 1.3.0.
  static const int homeTab = 0;
  static const int feedTab = 1;
  static const int liveTab = 2;
  static const int profileTab = 3;

  /// Snaps and Live are full-screen video.
  static bool isVideoTab(int index) => index == homeTab || index == liveTab;

  RxInt selectedPageIndex = 0.obs;
  RxDouble scaleValue = 1.0.obs;
  Function(int index)? onBottomIndexChanged;
  Rx<PostUploadingProgress> postProgress = Rx(PostUploadingProgress());
  Function(PostUploadingProgress progress) onProgress = (_) {};

  late AnimationController animationController;

  RxInt unReadCount = 0.obs;
  RxInt requestUnReadCount = 0.obs;

  StreamSubscription? _unReadCountSubscription;
  Timer? _lastUsedAtTimer;
  Timer? _unreadPollTimer;
  late Animation<double> scaleAnimation;
  User? user = SessionManager.instance.getUser();

  bool get isGuest => !SessionManager.instance.isLogin();

  @override
  void onInit() {
    super.onInit();
    if (selectedPageIndex.value == 0) {
      SystemChrome.setSystemUIOverlayStyle(const SystemUiOverlayStyle(statusBarBrightness: Brightness.dark));
    }
    Get.put(GifSheetController());
    Get.put(AppUserCacheController());
    Get.put(AdsController());
    animationController = AnimationController(duration: const Duration(milliseconds: 200), vsync: this);
    scaleAnimation = Tween<double>(begin: 0.5, end: 1.0).animate(
      CurvedAnimation(parent: animationController, curve: Curves.easeInOut),
    )..addListener(() {
        scaleValue.value = scaleAnimation.value; // Update reactive scale value
      });
    onProgress = (progress) {
      postProgress.value = progress;
    };
  }

  @override
  void onReady() async {
    super.onReady();
    // Everything below needs a logged-in user: realtime channels keyed on
    // the user id, auth-only APIs, FCM topic subscriptions.
    if (isGuest) return;

    RealtimeService.instance.connect();
    SubscriptionManager.shared.subscriptionListener();
    _fetchLanguageFromUser();
    _fetchUnReadCount();
    refreshUnreadActivity();
    // Whatever arrived while the app was in the background shows up on the
    // badges as soon as it is back.
    _appLifecycle = AppLifecycleListener(onResume: () {
      refreshUnreadActivity();
      refreshUnreadCounts();
    });
    startCacheCleanupScheduler();
    _subscribeFollowUserIds();
  }

  AppLifecycleListener? _appLifecycle;

  /// Counts activity (follows, gifts, likes, comments) newer than what the
  /// user last saw, for the bell and the Feed tab. Read from the server, so
  /// what arrived while the app was closed counts too — pushes alone only
  /// counted what landed while it was open.
  Future<void> refreshUnreadActivity() async {
    if (isGuest) return;
    try {
      final items = await NotificationService.instance.fetchActivityNotifications();
      final int lastSeen = SessionManager.instance.lastSeenActivityId;
      SessionManager.instance.setUnreadActivity(
          items.where((item) => (item.id ?? 0) > lastSeen).length);
    } catch (e) {
      Loggers.error('refreshUnreadActivity failed: $e');
    }
  }

  void startCacheCleanupScheduler() {
    UserService.instance.updateLastUsedAt();
    _lastUsedAtTimer = Timer.periodic(const Duration(minutes: 15), (_) {
      UserService.instance.updateLastUsedAt();
    });
  }

  @override
  void onClose() {
    animationController.dispose();
    _unReadCountSubscription?.cancel();
    _lastUsedAtTimer?.cancel();
    _unreadPollTimer?.cancel();
    _appLifecycle?.dispose();
    super.onClose();
  }

  onChanged(int index) {
    // The profile needs a session — prompt guests to sign in.
    if (index == profileTab && !AuthGate.check()) return;
    SystemChrome.setSystemUIOverlayStyle(SystemUiOverlayStyle(
        statusBarBrightness: isVideoTab(index) ? Brightness.dark : Brightness.light));
    if (index == feedTab) {
      onFeedPostScrollDown(index);
    }
    if (selectedPageIndex.value == index) return;
    HapticFeedback.lightImpact();
    onBottomIndexChanged?.call(index);
    selectedPageIndex.value = index;
    // A closed controller's animation is disposed, and reset() then throws
    // a null-check error (Crashlytics' second biggest issue, on every tab
    // tap after signing in; see DashboardHandoff for how it got closed).
    if (isClosed) return;
    animationController
      ..reset()
      ..forward();
  }

  /// The bar's ➕: post, story, snap or go live.
  void openCreateSheet() {
    if (!AuthGate.check()) return;
    const tag = ProfileScreenController.tag;
    final controller = Get.isRegistered<ProfileScreenController>(tag: tag)
        ? Get.find<ProfileScreenController>(tag: tag)
        : Get.put(
            ProfileScreenController(
                SessionManager.instance.getUser().obs, (user) {}),
            tag: tag);
    HapticFeedback.lightImpact();
    Get.bottomSheet(PostOptionsSheet(controller: controller),
        isScrollControlled: true);
  }

  onFeedPostScrollDown(int index) {
    if (selectedPageIndex.value != index) return;
    if (Get.isRegistered<FeedScreenController>()) {
      final controller = Get.find<FeedScreenController>();
      if (controller.posts.isNotEmpty && !controller.isLoading.value) {
        controller.postScrollController
            .animateTo(0.0, duration: const Duration(milliseconds: 150), curve: Curves.linear);
        controller.refreshKey.currentState?.show();
      }
    }
  }

  void _fetchUnReadCount() {
    refreshUnreadCounts();
    // Realtime user-channel events bump the badge instantly; a slow poll
    // covers WebSocket-down (or realtime-disabled) periods.
    _unReadCountSubscription =
        RealtimeService.instance.events.listen((event) {
      if (event.channel.startsWith('private-user.')) {
        refreshUnreadCounts();
      }
    });
    _unreadPollTimer = Timer.periodic(const Duration(seconds: 30), (_) {
      if (!RealtimeService.instance.isConnected) refreshUnreadCounts();
    });
  }

  Future<void> refreshUnreadCounts() async {
    if (isGuest) return;
    try {
      final counts = await ChatService.instance.fetchUnreadCounts();
      unReadCount.value = counts.unreadThreadCount;
      requestUnReadCount.value = counts.requestUnreadThreadCount;
    } catch (e) {
      Loggers.error('fetchUnreadCounts failed: $e');
    }
  }

  Future<void> _fetchLanguageFromUser() async {
    String savedLanguage = SessionManager.instance.getLang();
    String userLanguage = user?.appLanguage ?? 'en';
    if (userLanguage != savedLanguage) {
      SessionManager.instance.setLang(userLanguage);
      // updateLocale rebuilds translated widgets in place. This used to tear
      // down and rebuild the entire widget tree via RestartWidget, which threw
      // away every controller and cached screen just to change a language.
      WidgetsBinding.instance.addPostFrameCallback((_) {
        Get.updateLocale(Locale(userLanguage));
      });
    }
  }

  void _subscribeFollowUserIds() async {
    Future.wait([addUserInFirebase()]);
    for (int id in (user?.followingIds ?? [])) {
      // Delay slightly to avoid overloading FCM
      await Future.delayed(const Duration(milliseconds: 100));
      Future.wait([FirebaseNotificationManager.instance.subscribeToTopic(topic: '$id')]);
    }
  }

  Future addUserInFirebase() async {
    if (Get.isRegistered<AppUserCacheController>()) {
      Get.find<AppUserCacheController>().addUser(user);
    } else {
      Get.put(AppUserCacheController()).addUser(user);
    }
  }

}

class PostUploadingProgress {
  final CameraScreenType type;
  final UploadType uploadType;
  final double progress;

  PostUploadingProgress({this.type = CameraScreenType.post, this.progress = 0, this.uploadType = UploadType.none});
}

enum UploadType {
  none,
  finish,
  error,
  uploading;

  String title(CameraScreenType type) {
    switch (this) {
      case UploadType.none:
        return '';
      case UploadType.finish:
        return type == CameraScreenType.post ? LKey.postUploadSuccessfully.tr : LKey.storyUploadSuccess.tr;
      case UploadType.error:
        return LKey.uploadingFailed.tr;
      case UploadType.uploading:
        return type == CameraScreenType.post ? LKey.postIsBeginUploading.tr : LKey.storyIsBeginUploading.tr;
    }
  }
}

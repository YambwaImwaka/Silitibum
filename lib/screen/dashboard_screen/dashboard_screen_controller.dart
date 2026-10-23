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
import 'package:shortzz/common/service/api/user_service.dart';
import 'package:shortzz/common/service/realtime/realtime_service.dart';
import 'package:shortzz/common/service/subscription/subscription_manager.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/model/general/settings_model.dart';
import 'package:shortzz/model/user_model/user_model.dart';
import 'package:shortzz/screen/camera_screen/camera_screen.dart';
import 'package:shortzz/screen/feed_screen/feed_screen_controller.dart';
import 'package:shortzz/screen/gif_sheet/gif_sheet_controller.dart';
import 'package:shortzz/utilities/asset_res.dart';
import 'package:zego_express_engine/zego_express_engine.dart';

class DashboardScreenController extends BaseController with GetSingleTickerProviderStateMixin {
  List<String> bottomIconList = [
    AssetRes.icReel,
    AssetRes.icPost,
    AssetRes.icLiveStream,
    AssetRes.icSearch,
    AssetRes.icChat,
    AssetRes.icProfile
  ];
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
    // Zego is NOT created here any more. Building the engine allocates
    // native audio/video resources on every cold start, for every user,
    // including the majority who never open the Live tab in that session.
    // ensureZegoEngine() is called lazily when Live is actually opened.

    // Everything below needs a logged-in user: realtime channels keyed on
    // the user id, auth-only APIs, FCM topic subscriptions.
    if (isGuest) return;

    RealtimeService.instance.connect();
    SubscriptionManager.shared.subscriptionListener();
    _fetchLanguageFromUser();
    _fetchUnReadCount();
    startCacheCleanupScheduler();
    _subscribeFollowUserIds();
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
    super.onClose();
  }

  onChanged(int index) {
    // Messages (4) and Profile (5) need a session — prompt guests to sign in.
    if ((index == 4 || index == 5) && !AuthGate.check()) return;
    // Live (2): build the Zego engine on first visit rather than at startup.
    if (index == 2) ensureZegoEngine();
    SystemChrome.setSystemUIOverlayStyle(
        SystemUiOverlayStyle(statusBarBrightness: index == 0 || index == 2 ? Brightness.dark : Brightness.light));
    if (index == 1) {
      onFeedPostScrollDown(index);
    }
    if (selectedPageIndex.value == index) return;
    HapticFeedback.lightImpact();
    onBottomIndexChanged?.call(index);
    selectedPageIndex.value = index;
    animationController
      ..reset()
      ..forward();
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

  /// True once the Zego engine has been built for this app session.
  bool _isZegoEngineReady = false;

  /// Creates the Zego engine on first use.
  ///
  /// This used to run for everyone during dashboard startup, allocating native
  /// A/V resources on every cold launch even though most sessions never open
  /// the Live tab. Called from onChanged() when Live is selected, and again
  /// defensively before going live, so it is ready by the time a room is
  /// joined without costing anything to users who never go there.
  Future<void> ensureZegoEngine() {
    if (_isZegoEngineReady) return Future.value();
    // Selecting the tab and the Live feed's first room both ask at once;
    // sharing the call in flight keeps the engine from being created twice.
    return _zegoEngineCreation ??=
        _createZegoEngine().whenComplete(() => _zegoEngineCreation = null);
  }

  Future<void>? _zegoEngineCreation;

  Future<void> _createZegoEngine() async {
    Setting? appSetting = SessionManager.instance.getSettings();
    // tryParse, not parse. zego_app_id is a nullable settings column, and an
    // unconfigured one comes back as "" — which is not null, so `?? '0'` never
    // fired and int.parse threw FormatException the moment a user opened the
    // Live tab. The `appId == 0` guard below was written for exactly that case
    // and was unreachable.
    int appId = int.tryParse(appSetting?.zegoAppId ?? '') ?? 0;
    if (appId == 0) {
      return Loggers.info('The Zego App ID is not configured.');
    }
    try {
      await ZegoExpressEngine.createEngineWithProfile(
          ZegoEngineProfile(appId, ZegoScenario.Default, appSign: appSetting?.zegoAppSign));
      _isZegoEngineReady = true;
    } on MissingPluginException catch (e) {
      Loggers.error('Create Zego Engine : ${e.message}');
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

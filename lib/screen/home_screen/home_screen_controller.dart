import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import 'package:get/get.dart';
import 'package:shortzz/common/controller/base_controller.dart';
import 'package:shortzz/common/manager/firebase_notification_manager.dart';
import 'package:shortzz/common/manager/logger.dart';
import 'package:shortzz/common/manager/session_manager.dart';
import 'package:shortzz/common/manager/share_manager.dart';
import 'package:shortzz/common/service/api/api_service.dart';
import 'package:shortzz/common/service/api/common_service.dart';
import 'package:shortzz/common/service/api/post_service.dart';
import 'package:shortzz/common/service/api/user_service.dart';
import 'package:shortzz/common/service/location/location_service.dart';
import 'package:shortzz/common/service/navigation/navigate_with_controller.dart';
import 'package:shortzz/common/service/subscription/subscription_manager.dart';
import 'package:shortzz/model/general/place_detail.dart';
import 'package:shortzz/model/post_story/post_by_id.dart';
import 'package:shortzz/model/post_story/post_model.dart';
import 'package:shortzz/model/user_model/user_model.dart';
import 'package:shortzz/screen/post_screen/single_post_screen.dart';
import 'package:shortzz/screen/reels_screen/reels_screen.dart';
import 'package:shortzz/screen/reels_screen/reels_screen_controller.dart';
import 'package:shortzz/screen/reels_screen/widget/ad_slot.dart';
import 'package:shortzz/screen/reels_screen/widget/reel_page_type.dart';
import 'package:shortzz/utilities/app_res.dart';

class HomeScreenController extends BaseController with GetSingleTickerProviderStateMixin {
  Rx<TabType> selectedReelCategory = TabType.values.first.obs;
  RxList<Post> reels = <Post>[].obs;
  late AnimationController controller;
  late Animation<double> animation;
  RxBool isAnimateTab = false.obs;
  StreamSubscription<String>? streamSubscription;
  CancelToken token = CancelToken();

  Rx<User?> get myUser => Rx(SessionManager.instance.getUser());

  bool get isGuest => !SessionManager.instance.isLogin();

  @override
  void onInit() {
    controller = AnimationController(duration: const Duration(milliseconds: 250), vsync: this);
    animation = CurvedAnimation(parent: controller, curve: Curves.linear);

    // Staggered, not concurrent. These four used to launch together in one
    // Future.wait, so the request the user is actually waiting on — the feed —
    // competed for a cold server and a cold connection pool with three things
    // nobody is looking at: _fetchLocation is an IP-geolocation lookup followed
    // by a profile update POST, and _onNotificationTap hits a platform channel
    // that is slow precisely during a cold start.
    onRefreshPage();

    // These two decide *where* the user lands (notification tap, shared link),
    // so they go as soon as the first frame is up rather than waiting longer.
    // Deferring by a frame is safe: getInitialMessage() is a pull the plugin
    // holds until consumed, and ShareManager listens on uriLinkStream, which
    // only carries links that arrive while running. It is also better — the
    // navigation now happens with the home screen already built under it.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (isClosed) return;
      _readDeepLink();
      _onNotificationTap();
    });

    // Pure background sync; nothing on screen depends on it. Let the feed
    // request have a clear run first.
    Future.delayed(const Duration(seconds: 2), () {
      if (isClosed) return;
      _fetchLocation();
    });

    super.onInit();
  }

  @override
  void onReady() {
    isLoading.value = true;
    super.onReady();
  }

  @override
  void onClose() {
    super.onClose();
    controller.dispose();
    streamSubscription?.cancel();
  }

  Future<void> _onNotificationTap() async {
    if (Platform.isIOS) {
      // Handle the iOS notification payload once
      final payload = FirebaseNotificationManager.instance.notificationPayload.value;
      if (payload.isNotEmpty) {
        FirebaseNotificationManager.instance.handleNotification(payload);
      }
    } else {
      // Set up a listener to handle future payload changes
      // Android: Get the message if the app was opened via notification
      RemoteMessage? message = await FirebaseMessaging.instance.getInitialMessage();

      if (message != null) {
        await FirebaseNotificationManager.instance.handleNotification(jsonEncode(message.toMap()));
      }
    }

    // Assigned to streamSubscription (cancelled in onClose) — previously the
    // subscription was discarded here, so every HomeScreenController
    // recreation (logout/login, language switch restart, etc.) permanently
    // added another live listener on this app-lifetime singleton stream,
    // leaking memory and duplicating notification handling over a long
    // session.
    streamSubscription =
        FirebaseNotificationManager.instance.notificationPayload.listen((p0) {
      if (p0.isNotEmpty) {
        FirebaseNotificationManager.instance.handleNotification(p0);
      }
    });
  }

  Future<void> _readDeepLink() async {
    ShareManager.shared.listen((key, value) async {
      await Future.delayed(const Duration(milliseconds: 500));
      if (key == ShareKeys.post.value) {
        PostByIdModel model = await PostService.instance.fetchPostById(postId: value);
        if (model.status == true) {
          Post? post = model.data?.post;
          if (post != null) {
            await Get.to(() => SinglePostScreen(post: post, isFromNotification: true), preventDuplicates: false);
          }
        }
      } else if (key == ShareKeys.reel.value) {
        PostByIdModel model = await PostService.instance.fetchPostById(postId: value);
        if (model.status == true) {
          Post? post = model.data?.post;
          if (post != null) {
            await Get.to(
                () => ReelsScreen(reels: [post].obs, position: 0, pageType: ReelPageType.home),
                preventDuplicates: false);
          }
        }
      } else if (key == ShareKeys.user.value) {
        User? user = await UserService.instance.fetchUserDetails(userId: value);
        if (user != null) {
          await NavigationService.shared.openProfileScreen(user);
        }
      }
    });
  }

  Future<void> onRefreshPage({bool reset = true}) async {
    if (reset) {
      isLoading.value = true;
    }
    switch (selectedReelCategory.value) {
      case TabType.discover:
        await fetchDiscoverPost(reset);
        break;
      case TabType.following:
        await _fetchFollowingPost(reset);
        break;
      case TabType.nearby:
        try {
          await _fetchPostsNearBy(reset);
        } catch (e) {
          // No location permission / lookup failed: fall back to discover
          // and actually load it instead of showing an empty nearby feed.
          selectedReelCategory.value = TabType.discover;
          await fetchDiscoverPost(reset);
        }
        break;
    }
  }

  onTabTypeChanged(TabType tabType) async {
    onAnimationBack();
    if (selectedReelCategory.value == tabType) {
      return;
    }
    selectedReelCategory.value = tabType;
    await onRefreshPage.call(reset: true);
  }

  onToggleDropDown() {
    // Closed, its animation controller is disposed and forward() throws.
    if (isClosed) return;
    if (animation.status != AnimationStatus.completed) {
      controller.forward();
      isAnimateTab.value = true;
    } else {
      onAnimationBack();
    }
  }

  onAnimationBack() {
    isAnimateTab.value = false;
    if (isClosed) return;
    controller.animateBack(0, duration: const Duration(milliseconds: 250), curve: Curves.linear);
  }

  Future<void> fetchDiscoverPost(bool resetData) async {
    isLoading.value = true;
    try {
      List<Post> newPosts = await PostService.instance.fetchPostsDiscover(
          type: PostType.reels,
          // A reset is a fresh feed, so nothing is "already served" yet.
          excludeIds: resetData ? null : _servedPostIds,
          cancelToken: token);
      addResponseData(newPosts, resetData);
    } catch (e) {
      // A failed fetch must never leave the shimmer running forever.
      Loggers.error('fetchDiscoverPost failed: $e');
      addResponseData([], resetData);
    }
  }

  Future<void> _fetchFollowingPost(bool resetData) async {
    // Guests follow nobody — the endpoint is auth-only; show the sign-in state.
    if (isGuest) {
      addResponseData([], resetData);
      return;
    }
    isLoading.value = true;
    try {
      List<Post> newPosts = await PostService.instance.fetchPostsFollowing(type: PostType.reels, cancelToken: token);
      addResponseData(newPosts, resetData);
    } catch (e) {
      Loggers.error('fetchFollowingPost failed: $e');
      addResponseData([], resetData);
    }
  }

  Future<void> _fetchPostsNearBy(bool resetData) async {
    isLoading.value = true;
    try {
      Position position = await LocationService.instance.getCurrentLocation(isPermissionDialogShow: true);
      List<Post> newPosts = await PostService.instance.fetchPostsNearBy(
          type: PostType.reels, placeLat: position.latitude, placeLon: position.longitude, cancelToken: token);
      addResponseData(newPosts, resetData);
    } catch (e) {
      Loggers.error('fetchPostsNearBy failed: $e');
      addResponseData([], resetData);
      rethrow; // onRefreshPage falls back to the discover tab on location errors
    }
  }

  void addResponseData(List<Post> newPosts, bool resetData) {
    if (resetData) {
      reels.clear();
      _organicPostsSinceLastAd = 0;
      if (Get.isRegistered<ReelsScreenController>(tag: ReelsScreenController.tag)) {
        var controller = Get.find<ReelsScreenController>(tag: ReelsScreenController.tag);
        controller.handleRefresh(() async {});
      }
    }
    if (newPosts.isNotEmpty) {
      reels.addAll(_interleaveAds(newPosts));
    }

    isLoading.value = false;
  }

  /// Organic Snaps served since the last ad slot. Carried across pages so the
  /// cadence is continuous — counting per-page would put an ad at the same
  /// offset in every batch, or drop it entirely on short pages.
  int _organicPostsSinceLastAd = 0;

  /// Inserts a sponsored slot every `native_ad_frequency` organic Snaps.
  ///
  /// Done client-side rather than in the ranked API response so ad cadence stays
  /// independent of ranking, and so a settings change takes effect on the next
  /// fetch with no API change.
  List<Post> _interleaveAds(List<Post> posts) {
    final setting = SessionManager.instance.getSettings();
    final frequency = setting?.nativeAdFrequency ?? 0;

    final isPlatformAdsOn = (Platform.isAndroid && setting?.admobAndroidStatus == 1) ||
        (Platform.isIOS && setting?.admobIosStatus == 1);
    final isEnabled = !isSubscribe.value && setting?.nativeAdStatus == 1 && isPlatformAdsOn;

    if (!isEnabled || frequency <= 0) return posts;

    final out = <Post>[];
    for (final post in posts) {
      out.add(post);
      _organicPostsSinceLastAd++;
      if (_organicPostsSinceLastAd >= frequency) {
        out.add(AdSlot.build());
        _organicPostsSinceLastAd = 0;
      }
    }
    return out;
  }

  /// Post ids already served this session, for the ranked feed's `exclude_ids`.
  ///
  /// The ranked "For You" feed scores a candidate pool per call instead of
  /// paging a cursor, so without this the same top-scoring Snaps come back on
  /// every load-more. Filters ids <= 0, which drops both the -1 local-upload
  /// and -2 ad sentinels.
  List<int> get _servedPostIds {
    final ids = reels
        .map((post) => post.id)
        .whereType<int>()
        .where((id) => id > 0)
        .toList();

    // Keep the most recently served ids (the tail — reels is in feed order) and
    // cap the list. The server bounds this too; an uncapped list would grow the
    // request body for the whole session.
    if (ids.length <= 150) return ids;
    return ids.sublist(ids.length - 150);
  }

  Future<void> _fetchLocation() async {
    // Only syncs the region to the user's profile — meaningless (and 401) for guests.
    if (isGuest) return;

    PlaceDetail? detail;
    try {
      detail = await CommonService.instance.getIPPlaceDetail();
    } catch (e) {
      Loggers.error('Location error : $e');
    }

    if (detail != null) {
      UserService.instance
          .updateUserDetails(region: detail.region, regionName: detail.regionName, timezone: detail.timezone);
    }
  }
}

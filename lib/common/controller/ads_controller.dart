import 'dart:io';

import 'package:get/get.dart';
import 'package:google_mobile_ads/google_mobile_ads.dart';
import 'package:shortzz/common/controller/base_controller.dart';
import 'package:shortzz/common/manager/ads_manager.dart';
import 'package:shortzz/common/manager/logger.dart';
import 'package:shortzz/common/manager/session_manager.dart';
import 'package:shortzz/common/service/subscription/subscription_manager.dart';

class AdsController extends BaseController {
  InterstitialAd? interstitialAd;

  @override
  void onInit() {
    super.onInit();
    loadInterstitialAd(); // preload when controller initializes
  }

  Future<void> showInterstitialAdIfAvailable({bool isPopScope = false}) async {
    final setting = SessionManager.instance.getSettings();

    // Check ad status for platform
    final isAdDisabled =
        (Platform.isAndroid && setting?.admobAndroidStatus == 0) ||
            (Platform.isIOS && setting?.admobIosStatus == 0);

    final InterstitialAd? ad = interstitialAd;

    // Early return if ads are disabled or user is subscribed or ad is not loaded
    if (isAdDisabled || isSubscribe.value || ad == null) {
      if (!isPopScope) {
        Get.back();
      }
      return;
    }
    if (!isPopScope) {
      Get.back();
    }
    // An ad shows once and is disposed when it closes; its callbacks load
    // the next. Kept here, the disposed ad was shown again next time and
    // the plugin threw (NullPointerException in Integer.intValue()).
    interstitialAd = null;
    try {
      await ad.show();
    } catch (e) {
      Loggers.error('Interstitial ad failed to show: $e');
      ad.dispose();
      loadInterstitialAd();
    }
  }

  Future<void> loadInterstitialAd() async {
    if (isSubscribe.value) return;

    AdsManager.instance.loadInterstitialAd(onAdLoaded: (ad) {
      interstitialAd = ad;

      interstitialAd?.fullScreenContentCallback = FullScreenContentCallback(
        onAdDismissedFullScreenContent: (ad) {
          ad.dispose();
          loadInterstitialAd(); // Reload for next time
        },
        onAdFailedToShowFullScreenContent: (ad, error) {
          ad.dispose();
          loadInterstitialAd();
        },
      );
    });
  }
}

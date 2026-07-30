import 'dart:developer';
import 'dart:io';

import 'package:google_mobile_ads/google_mobile_ads.dart';
import 'package:shortzz/common/manager/session_manager.dart';
import 'package:shortzz/model/general/settings_model.dart';

class AdsManager {
  AdsManager._();

  static final instance = AdsManager._();

  void loadBannerAd({required Function(Ad) onAdLoaded}) async {
    Setting? setting = SessionManager.instance.getSettings();
    if (Platform.isAndroid && setting?.admobAndroidStatus == 0) {
      return;
    }
    if (Platform.isIOS && setting?.admobIosStatus == 0) {
      return;
    }
    BannerAd(
      adUnitId: Platform.isAndroid
          ? (setting?.admobBanner ?? '')
          : (setting?.admobBannerIos ?? ''),
      request: const AdRequest(),
      size: AdSize.banner,
      listener: BannerAdListener(
        onAdLoaded: onAdLoaded,
        onAdFailedToLoad: (ad, err) {
          ad.dispose();
        },
      ),
    ).load();
  }

  Future<void> loadInterstitialAd(
      {required Function(InterstitialAd) onAdLoaded}) async {
    Setting? setting = SessionManager.instance.getSettings();
    if (Platform.isAndroid && setting?.admobAndroidStatus == 0) {
      return;
    }
    if (Platform.isIOS && setting?.admobIosStatus == 0) {
      return;
    }
    await InterstitialAd.load(
        adUnitId: Platform.isAndroid
            ? (setting?.admobInt ?? '')
            : (setting?.admobIntIos ?? ''),
        request: const AdRequest(),
        adLoadCallback: InterstitialAdLoadCallback(
          onAdLoaded: onAdLoaded,
          onAdFailedToLoad: (LoadAdError error) {
            log('InterstitialAd failed to load: $error');
          },
        ));
  }

  /// Loads the in-feed native ad rendered by [NativeAdReelCard].
  ///
  /// Gated on BOTH the native-ad switch and the per-platform admob switch: an
  /// operator needs to be able to run banners/interstitials while in-feed ads
  /// are off, and killing all ads on a platform must kill these too.
  ///
  /// `factoryId` must match the factory registered natively — MainActivity.kt
  /// on Android, AppDelegate.swift on iOS.
  void loadNativeAd({
    required Function(Ad) onAdLoaded,
    required Function() onAdFailedToLoad,
  }) {
    Setting? setting = SessionManager.instance.getSettings();
    if (setting?.nativeAdStatus != 1) {
      onAdFailedToLoad();
      return;
    }
    if (Platform.isAndroid && setting?.admobAndroidStatus == 0) {
      onAdFailedToLoad();
      return;
    }
    if (Platform.isIOS && setting?.admobIosStatus == 0) {
      onAdFailedToLoad();
      return;
    }

    final adUnitId = Platform.isAndroid
        ? (setting?.nativeAdAndroid ?? '')
        : (setting?.nativeAdIos ?? '');
    if (adUnitId.isEmpty) {
      // Status on but no unit configured for this platform — requesting with an
      // empty id throws inside the plugin.
      onAdFailedToLoad();
      return;
    }

    NativeAd(
      adUnitId: adUnitId,
      factoryId: 'snapNativeAdFactory',
      request: const AdRequest(),
      listener: NativeAdListener(
        onAdLoaded: onAdLoaded,
        onAdFailedToLoad: (ad, err) {
          log('NativeAd failed to load: $err');
          ad.dispose();
          onAdFailedToLoad();
        },
      ),
    ).load();
  }

  void requestConsentInfoUpdate() {
    final params = ConsentRequestParameters(
        consentDebugSettings: ConsentDebugSettings(
            debugGeography: DebugGeography.debugGeographyEea,
            testIdentifiers: ['D5E5A833CA124D2CD5E33A574AF9EA88']));
    ConsentInformation.instance.requestConsentInfoUpdate(
      params,
      () async {
        if (await ConsentInformation.instance.isConsentFormAvailable()) {
          loadForm();
        }
      },
      (FormError error) {
        // Handle the error
      },
    );
  }

  void loadForm() {
    ConsentForm.loadConsentForm(
      (ConsentForm consentForm) async {
        var status = await ConsentInformation.instance.getConsentStatus();
        if (status == ConsentStatus.required) {
          consentForm.show((formError) {
            loadForm();
          });
        }
      },
      (FormError formError) {
        // Handle the error
      },
    );
  }
}

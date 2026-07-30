import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:google_mobile_ads/google_mobile_ads.dart';
import 'package:shortzz/common/manager/ads_manager.dart';
import 'package:shortzz/common/service/subscription/subscription_manager.dart';

/// One full-screen sponsored card inside the Snaps feed.
///
/// Mirrors [BannerAdsCustom]'s lifecycle (load in initState, dispose in
/// dispose), but the view itself is built natively — see native_ad_layout.xml /
/// NativeAdFactory.kt on Android and NativeAdFactory.swift on iOS — so the card
/// fills the page like a Snap instead of sitting in a banner strip.
///
/// Renders a plain black page while loading and if the load fails. Ads are
/// requested per-slot and a failure is normal (no fill, network down, ad unit
/// misconfigured); it must never surface as an error to the viewer.
class NativeAdReelCard extends StatefulWidget {
  const NativeAdReelCard({super.key});

  @override
  State<NativeAdReelCard> createState() => _NativeAdReelCardState();
}

class _NativeAdReelCardState extends State<NativeAdReelCard> {
  NativeAd? _nativeAd;
  bool _isLoaded = false;

  @override
  void initState() {
    super.initState();
    _loadAd();
  }

  @override
  void dispose() {
    _nativeAd?.dispose();
    super.dispose();
  }

  void _loadAd() {
    if (isSubscribe.value) return;

    AdsManager.instance.loadNativeAd(
      onAdLoaded: (ad) {
        // The page can be swiped away mid-load; adopting the ad into a
        // disposed State would leak it and setState would throw.
        if (!mounted) {
          ad.dispose();
          return;
        }
        setState(() {
          _nativeAd = ad as NativeAd;
          _isLoaded = true;
        });
      },
      onAdFailedToLoad: () {
        if (!mounted) return;
        setState(() {
          _nativeAd = null;
          _isLoaded = false;
        });
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    return Obx(() {
      // A subscription bought mid-session should empty the slot immediately
      // rather than waiting for the next feed fetch to stop inserting them.
      if (isSubscribe.value) return const ColoredBox(color: Colors.black);

      return ColoredBox(
        color: Colors.black,
        child: _isLoaded && _nativeAd != null
            ? AdWidget(ad: _nativeAd!)
            : const SizedBox.expand(),
      );
    });
  }
}

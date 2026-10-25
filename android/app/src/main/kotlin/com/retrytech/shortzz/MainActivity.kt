package com.silitibum.app

import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugins.googlemobileads.GoogleMobileAdsPlugin

class MainActivity : FlutterActivity() {

    // The override exists ONLY to register the native ad factory.
    //
    // It must not go back to hand-registering plugins. FlutterActivity's own
    // implementation (called via super below) already runs
    // GeneratedPluginRegistrant, which registers every plugin in pubspec.yaml —
    // including purchases_flutter, sqflite, video_player, the live video
    // plugin, permission_handler, just_audio and retrytech_plugin. This class used to
    // call super and THEN add those seven again by hand. FlutterEngine refuses
    // the duplicates ("Attempted to register plugin ... but it was already
    // registered"), so it bought nothing, but each `add()` still had to load and
    // construct the plugin class first — dex verification for one of them alone
    // was measured at 115ms — and all of it landed on the main thread inside
    // cold start, right where the app was dropping 100+ frame batches before
    // first paint.
    //
    // registerNativeAdFactory is not a plugin registration: it hands a view
    // builder to the already-registered GoogleMobileAdsPlugin, so it has to run
    // after super, and it constructs nothing expensive.
    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)

        GoogleMobileAdsPlugin.registerNativeAdFactory(
            flutterEngine,
            NATIVE_AD_FACTORY_ID,
            SnapNativeAdFactory(layoutInflater)
        )
    }

    override fun cleanUpFlutterEngine(flutterEngine: FlutterEngine) {
        // The factory holds this Activity's LayoutInflater; leaving it attached
        // to a torn-down engine leaks the Activity.
        GoogleMobileAdsPlugin.unregisterNativeAdFactory(flutterEngine, NATIVE_AD_FACTORY_ID)
        super.cleanUpFlutterEngine(flutterEngine)
    }

    private companion object {
        // Must match the factoryId passed to NativeAd() in Dart.
        const val NATIVE_AD_FACTORY_ID = "snapNativeAdFactory"
    }
}

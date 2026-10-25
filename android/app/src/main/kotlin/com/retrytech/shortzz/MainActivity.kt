package com.silitibum.app

import android.util.Log
import com.deepar.ai.DeepArPlugin
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel
import io.flutter.plugins.googlemobileads.GoogleMobileAdsPlugin

class MainActivity : FlutterActivity() {

    // The override exists to register the native ad factory and to make the
    // DeepAR plugin safe to tear down.
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

        guardDeepArDetach(flutterEngine)

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

    // deepar_flutter_plus 0.2.1 (the latest release) creates its two method
    // channels only when the activity attaches and when the filter camera
    // opens, yet its onDetachedFromEngine uses both without a null check. So
    // whenever the engine was torn down before the filter camera had been
    // used — Android recreating the activity for a system change such as a
    // SIM swap or new wallpaper colours, or Back on Android 11 and older — the
    // app crashed with a NullPointerException. Placeholder channels make that
    // teardown safe; the plugin still replaces them with its real channels
    // exactly as before. proguard-rules.pro keeps the plugin's field names.
    private fun guardDeepArDetach(engine: FlutterEngine) {
        val plugin = engine.plugins.get(DeepArPlugin::class.java) ?: return
        for (name in arrayOf("channel", "cameraXChannel")) {
            try {
                val field = DeepArPlugin::class.java.getDeclaredField(name)
                field.isAccessible = true
                if (field.get(plugin) == null) {
                    field.set(
                        plugin,
                        MethodChannel(engine.dartExecutor.binaryMessenger, "silitibum/deep_ar_placeholder/$name")
                    )
                }
            } catch (e: Exception) {
                Log.w("MainActivity", "DeepAR teardown guard skipped $name: $e")
            }
        }
    }

    private companion object {
        // Must match the factoryId passed to NativeAd() in Dart.
        const val NATIVE_AD_FACTORY_ID = "snapNativeAdFactory"
    }
}

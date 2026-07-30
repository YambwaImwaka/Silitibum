import Flutter
import GoogleMaps
import UIKit
import flutter_local_notifications
import AVFoundation
import app_links
import google_mobile_ads

@main
@objc class AppDelegate: FlutterAppDelegate {
    override func application(
        _ application: UIApplication,
        didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
    ) -> Bool {
        // This is required to make any communication available in the action isolate.
        FlutterLocalNotificationsPlugin.setPluginRegistrantCallback { (registry) in
            GeneratedPluginRegistrant.register(with: registry)
        }

        UNUserNotificationCenter.current().delegate = self as UNUserNotificationCenterDelegate
        GMSServices.provideAPIKey("____ GOOGLE API ______")
        GeneratedPluginRegistrant.register(with: self)

        // Must run after GeneratedPluginRegistrant — this hands a view builder to
        // the already-registered google_mobile_ads plugin rather than registering
        // a plugin of its own. The factoryId must match the one Dart requests
        // (NativeAdReelCard) and the Android side (MainActivity).
        FLTGoogleMobileAdsPlugin.registerNativeAdFactory(
            self, factoryId: "snapNativeAdFactory",
            nativeAdFactory: SnapNativeAdFactory())

        if let url = AppLinks.shared.getLink(launchOptions: launchOptions) {
                  // We have a link, propagate it to your Flutter app or not
                  AppLinks.shared.handleLink(url: url)
                }
        return super.application(application, didFinishLaunchingWithOptions: launchOptions)
    }
}

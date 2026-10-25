import 'dart:async';
import 'dart:io';

import 'package:audio_session/audio_session.dart';
import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_crashlytics/firebase_crashlytics.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:get_storage/get_storage.dart';
import 'package:google_mobile_ads/google_mobile_ads.dart';
import 'package:shortzz/common/manager/firebase_notification_manager.dart';
import 'package:shortzz/common/manager/logger.dart';
import 'package:shortzz/common/manager/session_manager.dart';
import 'package:shortzz/common/service/api/api_service.dart';
import 'package:shortzz/common/service/subscription/subscription_manager.dart';
import 'package:shortzz/common/widget/restart_widget.dart';
import 'package:shortzz/languages/dynamic_translations.dart';
import 'package:shortzz/screen/splash_screen/splash_screen.dart';
import 'package:shortzz/utilities/theme_res.dart';

import 'common/service/network_helper/network_helper.dart';

@pragma('vm:entry-point')
Future<void> _firebaseMessagingBackgroundHandler(RemoteMessage message) async {
  Loggers.success("Handling a background message: ${message.data}");
  await Firebase.initializeApp();
  if (Platform.isIOS) {
    FirebaseNotificationManager.instance.showNotification(message);
  }
}

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  try {
    await Firebase.initializeApp();

    // Crash reporting only — Crashlytics never sees auth/user data, it's
    // purely uncaught-error telemetry. Disabled in debug builds so local
    // development noise doesn't pollute the dashboard.
    await FirebaseCrashlytics.instance
        .setCrashlyticsCollectionEnabled(!kDebugMode);
    // Assigning onError replaces the framework's default handler outright, so
    // without presentError() nothing reaches the console. Combined with the
    // kDebugMode guard above that meant framework errors vanished entirely in
    // debug builds — a red ErrorWidget on screen and no stack trace in logcat
    // or Crashlytics. presentError() restores the console dump; Crashlytics
    // still receives the error in release exactly as before.
    //
    // A request that fails because the network did (an unawaited call on a
    // weak connection) is reported as non-fatal. Reported as fatal, these
    // filled Crashlytics' crash list although the app kept running.
    FlutterError.onError = (details) {
      FlutterError.presentError(details);
      if (NetworkException.isNetworkError(details.exception)) {
        FirebaseCrashlytics.instance.recordFlutterError(details);
      } else {
        FirebaseCrashlytics.instance.recordFlutterFatalError(details);
      }
    };
    PlatformDispatcher.instance.onError = (error, stack) {
      FirebaseCrashlytics.instance.recordError(error, stack,
          fatal: !NetworkException.isNetworkError(error));
      return true;
    };

    // What the user SEES when a widget throws during build. Without this,
    // release builds show Flutter's default error box — a grey/black panel
    // with exception text — in place of the offending widget. In a feed that
    // is per-item, so one malformed post renders a raw stack trace inside the
    // list. onError above still reports it; this only controls the visuals.
    //
    // Debug keeps the default red screen, which is the useful one to develop
    // against.
    if (!kDebugMode) {
      ErrorWidget.builder = (details) => const SizedBox.shrink();
    }

    // Register background handler
    FirebaseMessaging.onBackgroundMessage(_firebaseMessagingBackgroundHandler);

    await GetStorage.init('shortzz');

    NetworkHelper().initialize();

    // Load Translations
    Get.put(DynamicTranslations());

    // Run app
    runApp(const RestartWidget(child: MyApp()));

    // Everything below is deliberately AFTER runApp and un-awaited: none of it
    // is needed to paint the first frame, and each was previously blocking it.
    // RevenueCat in particular was an `await` with a 10s timeout, so a slow or
    // captive network delayed the splash by up to ten seconds before the user
    // saw anything at all.
    _initDeferredServices();
  } catch (e, st) {
    Loggers.error('Fatal crash during app startup: $e\n$st');
    // Best-effort: Firebase may not have finished initializing yet if the
    // crash happened before/during Firebase.initializeApp() itself.
    try {
      await FirebaseCrashlytics.instance.recordError(e, st, fatal: true);
    } catch (_) {}
  }
}

/// Third-party SDKs that must not gate the first frame.
///
/// Each is independent and failure-tolerant: a purchase SDK or ad network
/// being unreachable is not a reason for the user to stare at a blank screen,
/// so these run un-awaited after runApp and log rather than throw.
Future<void> _initDeferredServices() async {
  try {
    // .music(), not .speech(). The speech preset requests transient may-duck
    // focus with a spoken-audio content type, which is wrong for a media app:
    // it ducks against the reel player and left the music picker loading a
    // track that was never audible.
    (await AudioSession.instance)
        .configure(const AudioSessionConfiguration.music());
  } catch (e) {
    Loggers.error('AudioSession configure error: $e');
  }

  try {
    await SubscriptionManager.shared
        .initPlatformState()
        .timeout(const Duration(seconds: 10));
  } catch (e, st) {
    Loggers.error('SubscriptionManager init error: $e\n$st');
  }

  try {
    MobileAds.instance.initialize();
  } catch (e) {
    Loggers.error('MobileAds init error: $e');
  }
}

class MyApp extends StatelessWidget {
  const MyApp({super.key});

  @override
  Widget build(BuildContext context) {
    return GetMaterialApp(
      builder: (context, child) =>
          ScrollConfiguration(behavior: MyBehavior(), child: child!),
      translations: Get.find<DynamicTranslations>(),
      locale: Locale(SessionManager.instance.getLang()),
      fallbackLocale: Locale(SessionManager.instance.getFallbackLang()),
      themeMode: ThemeMode.light,
      darkTheme: ThemeRes.darkTheme(context),
      theme: ThemeRes.lightTheme(context),
      debugShowCheckedModeBanner: false,
      home: const SplashScreen(),
    );
  }
}

class MyBehavior extends ScrollBehavior {
  @override
  Widget buildOverscrollIndicator(
      BuildContext context, Widget child, ScrollableDetails details) {
    return child;
  }
}

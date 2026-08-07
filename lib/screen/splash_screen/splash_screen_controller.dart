import 'dart:async';
import 'dart:convert';

import 'package:csv/csv.dart';
import 'package:flutter/foundation.dart';
import 'package:get/get.dart';
import 'package:http/http.dart' as http;
import 'package:shortzz/common/controller/base_controller.dart';
import 'package:shortzz/common/extensions/string_extension.dart';
import 'package:shortzz/common/manager/logger.dart';
import 'package:shortzz/common/manager/session_manager.dart';
import 'package:shortzz/common/service/api/common_service.dart';
import 'package:shortzz/common/service/api/user_service.dart';
import 'package:shortzz/common/service/network_helper/network_helper.dart';
import 'package:shortzz/common/service/subscription/subscription_manager.dart';
import 'package:shortzz/common/widget/no_internet_sheet.dart';
import 'package:shortzz/languages/dynamic_translations.dart';
import 'package:shortzz/model/general/settings_model.dart';
import 'package:shortzz/model/user_model/user_model.dart';
import 'package:shortzz/screen/dashboard_screen/dashboard_screen.dart';
import 'package:shortzz/screen/on_boarding_screen/on_boarding_screen.dart';
import 'package:shortzz/screen/select_language_screen/select_language_screen.dart';
import 'package:shortzz/utilities/app_res.dart';

class SplashScreenController extends BaseController {
  late StreamSubscription _subscription;
  bool isOnline = true;
  bool _isNoInternetSheetOpen = false;

  @override
  void onReady() {
    super.onReady();

    // FIX: fetchSettings() is no longer fire-and-forget. Any exception
    // (network error, backend 500, timeout, JSON parse failure, etc.)
    // is now caught here so the splash can never hang silently — it
    // always falls back to the login screen instead.
    fetchSettings().catchError((e, st) {
    Loggers.error('fetchSettings failed, falling back to DashboardScreen: $e');
      if (Get.context != null) {
      Get.off(() => const DashboardScreen());
      }
    });

    _subscription = NetworkHelper().onConnectionChange.listen((status) {
      isOnline = status;
      if (isOnline) {
        // Only pop if WE pushed the sheet — a blind Get.back() here could
        // dismiss an unrelated screen on a spurious reconnect event.
        if (_isNoInternetSheetOpen) {
          _isNoInternetSheetOpen = false;
          Get.back();
        }
      } else if (!_isNoInternetSheetOpen) {
        _isNoInternetSheetOpen = true;
        Get.to(() => const NoInternetSheet(), transition: Transition.downToUp)
            ?.then((_) => _isNoInternetSheetOpen = false);
      }
    });
  }

  @override
  void onClose() {
    super.onClose();
    _subscription.cancel();
  }

  Future<void> fetchSettings() async {
    // No artificial minimum splash any more. The old flow held the screen for
    // a fixed second, then downloaded and parsed EVERY enabled language file,
    // then restarted the whole widget tree — all before the user saw anything.
    // Translations are now restored from a local cache synchronously (see
    // DynamicTranslations) and refreshed in the background, so the splash
    // lasts exactly as long as the settings call and nothing more.
    bool showNavigate = await CommonService.instance.fetchGlobalSettings();

    // FIX: previously, if showNavigate was false, nothing happened and the
    // app was stuck on the splash screen forever. Now it falls back to login.
    if (!showNavigate) {
      Loggers.error('fetchGlobalSettings() returned false — navigating to DashboardScreen as fallback');
      Get.off(() => const DashboardScreen());
      return;
    }

    final translations = Get.find<DynamicTranslations>();
    var setting = SessionManager.instance.getSettings();
    var languages = setting?.languages ?? [];
    List<Language> downloadLanguages = languages.where((element) => element.status == 1).toList();

    // Start the profile refetch NOW rather than after the translation
    // downloads and the widget-tree restart. It depends only on the restored
    // session, so running it in sequence just stacked another round trip on
    // top of the language CSVs — on a slow connection that was the difference
    // between one wait and two. Errors are folded into a null result here so
    // a failure can't escape as an unhandled rejection while nothing is
    // awaiting it yet.
    Future<User?>? userRequest;
    if (SessionManager.instance.isLogin()) {
      // RevenueCat identity has to be re-established on EVERY launch, not just
      // at login. Purchases.configure() in main() always starts an anonymous
      // customer, and a restored session used to reach the dashboard without
      // ever calling logIn() — so every returning user stayed
      // $RCAnonymousID:... Purchases made in that state are invisible to both
      // consumers of the id: WalletController::buyCoins looks the customer up
      // by numeric id and finds no purchase (store charges, coins never
      // credited), and RevenueCatWebhookController's ctype_digit() guard
      // no-ops, so the verified badge never lands.
      final rcUserId = SessionManager.instance.getUserID();
      if (rcUserId != 0) {
        SubscriptionManager.shared.login('$rcUserId');
      }
      userRequest = UserService.instance
          .fetchUserDetails(userId: SessionManager.instance.getUserID())
          .catchError((e, st) {
        Loggers.error('fetchUserDetails failed, falling back to cached user: $e');
        return null;
      });
    }

    var defaultLang = languages.firstWhereOrNull((element) => element.isDefault == 1);
    if (defaultLang != null) {
      SessionManager.instance.setFallbackLang(defaultLang.code ?? AppRes.baseLanguageCode);
    }

    // English is never downloaded. The LKey constants ARE the English strings
    // and GetX's `.tr` returns the key unchanged when a language has no entry
    // for it, so English.csv only ever produced an identity map — a network
    // round trip and a CSV parse to learn that "Snaps" translates to "Snaps".
    // The app is English-only for now, so in practice this skips language
    // loading entirely; the paths below stay in place for the local languages
    // coming in the next release.
    final translatableLanguages = downloadLanguages
        .where((e) => e.code != AppRes.baseLanguageCode)
        .toList();

    final String activeLang = SessionManager.instance.getLang();

    if (activeLang == AppRes.baseLanguageCode) {
      // Nothing to fetch and nothing to wait for. Any other enabled languages
      // are only needed once the user actually switches to one, which goes
      // through SelectLanguageScreen.
    } else if (translations.hasLanguage(activeLang)) {
      // Cache hit for the language being displayed — render from it now and
      // refresh quietly afterwards.
      _refreshLanguagesInBackground(translatableLanguages, translations);
    } else {
      // Cold cache: fetch only the language needed to render, and let any
      // others follow in the background. The old code awaited all of them.
      final needed =
          translatableLanguages.where((e) => e.code == activeLang).toList();
      if (needed.isNotEmpty) {
        translations.addTranslations(await downloadAndParseLanguages(needed));
      }
      if (translatableLanguages.length > needed.length) {
        _refreshLanguagesInBackground(translatableLanguages, translations);
      }
    }

    // RestartWidget.restartApp() used to run here — a full widget-tree
    // teardown and rebuild on every single launch, purely to make new
    // translations visible. Get.forceAppUpdate() rebuilds in place instead.

    if (userRequest != null) {
      // Already in flight since before the translation downloads — by now it
      // has usually resolved, so this await costs nothing. The session stays
      // logged in either way; on a failed refetch we still pass the
      // last-known cached user (rather than null) so the profile tab doesn't
      // render as "user not found" for someone who is actually signed in —
      // it'll just refresh again from there.
      final value = await userRequest;
      Get.off(() => DashboardScreen(myUser: value ?? SessionManager.instance.getUser()));
    } else {
      bool isLanguageSelect = SessionManager.instance.getBool(SessionKeys.isLanguageScreenSelect);
      bool onBoardingShow = SessionManager.instance.getBool(SessionKeys.isOnBoardingScreenSelect);
      if (isLanguageSelect == false) {
        Get.off(() => const SelectLanguageScreen(languageNavigationType: LanguageNavigationType.fromStart));
      } else if (onBoardingShow == false && (setting?.onBoarding ?? []).isNotEmpty) {
        Get.off(() => const OnBoardingScreen());
      } else {
        // Not logged in: proceed as guest to Dashboard
        Get.off(() => const DashboardScreen());
      }
    }
  }

  /// Refreshes the translation files without blocking navigation.
  ///
  /// Deliberately un-awaited by the caller: the app is already rendering from
  /// the cached table, so a slow CDN delays nothing the user can see. When
  /// fresh strings land, Get.forceAppUpdate() rebuilds the tree in place —
  /// far cheaper than the RestartWidget teardown this replaced.
  void _refreshLanguagesInBackground(
      List<Language> languages, DynamicTranslations translations) {
    () async {
      try {
        final files = await downloadAndParseLanguages(languages);
        if (files.isEmpty) return;
        translations.addTranslations(files);
        await Get.forceAppUpdate();
      } catch (e) {
        Loggers.error('Background language refresh failed: $e');
      }
    }();
  }

  Future<Map<String, Map<String, String>>> downloadAndParseLanguages(List<Language> languages) async {
    const int maxConcurrentDownloads = 3; // Limit concurrent downloads
    final Set<Future<void>> activeDownloads = {}; // Track active downloads
    final languageData = <String, Map<String, String>>{};

    for (var language in languages) {
      if (language.code != null && language.csvFile != null) {
        // Start the download and add it to the active set
        late final Future<void> downloadTask;
        downloadTask = downloadAndProcessLanguage(language, languageData).whenComplete(() {
          // FIX: previously `activeDownloads.removeWhere((task) => task ==
          // Future.any(activeDownloads))` compared each task against a brand
          // new Future instance returned by Future.any(), which could never
          // equal anything already in the set — completed downloads were
          // never actually removed, defeating the concurrency cap. Removing
          // the task from its own whenComplete callback fixes this.
          activeDownloads.remove(downloadTask);
        });
        activeDownloads.add(downloadTask);

        // Limit concurrency
        if (activeDownloads.length >= maxConcurrentDownloads) {
          await Future.any(activeDownloads);
        }
      }
    }

    // Wait for all remaining downloads to complete
    await Future.wait(activeDownloads);

    return languageData;
  }

  Future<void> downloadAndProcessLanguage(Language language, Map<String, Map<String, String>> languageData) async {
    try {
      // FIX: no timeout previously — a hung backend/CDN response would
      // block this download forever, which in turn blocked the whole
      // splash flow from ever navigating away.
      final response = await http
          .get(Uri.parse(language.csvFile?.addBaseURL() ?? ''))
          .timeout(const Duration(seconds: 10));

      if (response.statusCode == 200) {
        // utf8-decoding and CSV-parsing a translation file (thousands of rows)
        // used to happen inline on the UI isolate, at the exact moment the
        // splash is meant to be animating — one of the "Skipped N frames"
        // batches logcat reports during startup. compute() moves both onto a
        // worker isolate; only the finished map crosses back.
        final parsedMap = await compute(_decodeAndParseCsv, response.bodyBytes);
        languageData[language.code!] = parsedMap;

        Loggers.info('Downloaded and parsed: ${language.code}');
      } else {
        Loggers.error('Failed to download ${language.code}: ${response.statusCode}');
      }
    } catch (e) {
      // Includes TimeoutException now, in addition to network/parse errors.
      Loggers.error('Error downloading ${language.code}: $e');
    }
  }

}

/// Runs on a worker isolate via [compute], so it must stay a top-level
/// function and must not touch anything from the UI isolate.
Map<String, String> _decodeAndParseCsv(Uint8List bodyBytes) {
  final rows = const CsvToListConverter().convert(utf8.decode(bodyBytes));
  final map = <String, String>{};

  for (var row in rows) {
    if (row.length >= 2) {
      map[row[0].toString()] = row[1].toString();
    }
  }
  return map;
}
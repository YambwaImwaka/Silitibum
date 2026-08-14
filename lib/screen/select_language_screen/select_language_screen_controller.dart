import 'dart:io';

import 'package:get/get.dart';
import 'package:shortzz/common/controller/base_controller.dart';
import 'package:shortzz/common/manager/ads_manager.dart';
import 'package:shortzz/common/manager/logger.dart';
import 'package:shortzz/common/manager/session_manager.dart';
import 'package:shortzz/common/widget/eula_sheet.dart';
import 'package:shortzz/common/widget/restart_widget.dart';
import 'package:shortzz/model/general/settings_model.dart';
import 'package:shortzz/screen/select_language_screen/select_language_screen.dart';

class SelectLanguageScreenController extends BaseController {
  Rx<Language?> selectedLanguage = Rx(null);
  RxList<Language> languages = <Language>[].obs;
  LanguageNavigationType languageNavigationType;

  Setting? get setting => SessionManager.instance.getSettings();
  SelectLanguageScreenController(this.languageNavigationType);

  @override
  void onInit() {
    super.onInit();
    initLanguage();
  }

  @override
  void onReady() {
    super.onReady();
    if (languageNavigationType == LanguageNavigationType.fromStart) {
      openEULASheet();
    }
    AdsManager.instance.requestConsentInfoUpdate();
  }

  Future<void> openEULASheet() async {
    if (Platform.isIOS) {
      bool shouldOpen = SessionManager.instance.shouldOpenEULASheet;

      await Future.delayed(const Duration(milliseconds: 250));
      Loggers.info('message  $shouldOpen');
      if (shouldOpen) {
        Get.bottomSheet(const EulaSheet(),
            isScrollControlled: true, enableDrag: false);
      }
    }
  }

  void initLanguage() {
    List<Language> items =
        SessionManager.instance.getSettings()?.languages ?? [];
    items.sort((a, b) => (a.title ?? '').compareTo(b.title ?? ''));
    for (Language element in items) {
      if (element.status == 1) {
        languages.add(element);
      }
    }
    // firstWhereOrNull, not firstWhere. This runs from onInit() on the
    // first-launch path (splash routes a fresh install straight here), so an
    // unmatched lookup threw StateError before the screen could build — a red
    // screen on the very first open, with no way past it.
    //
    // Two ways it goes unmatched: no language row has status == 1, or none
    // matches getLang(). getLang() falls back to the is_default language, so
    // an admin toggling the only enabled language off is enough.
    //
    // The old `as Language?` cast read like a null guard but was dead code —
    // firstWhere returns non-null or throws, so the cast never ran.
    selectedLanguage.value = languages.firstWhereOrNull(
      (element) => element.code == SessionManager.instance.getLang(),
    );
  }

  void onLanguageChange(Language? value) {
    selectedLanguage.value = value;
    SessionManager.instance.setLang(value?.code ?? 'en');
    RestartWidget.restartApp(Get.context!);
  }
}

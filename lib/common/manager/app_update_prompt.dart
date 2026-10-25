import 'dart:io';

import 'package:figma_squircle_updated/figma_squircle.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:package_info_plus/package_info_plus.dart';
import 'package:shortzz/common/manager/logger.dart';
import 'package:shortzz/common/manager/session_manager.dart';
import 'package:shortzz/common/widget/text_button_custom.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/model/general/settings_model.dart';
import 'package:shortzz/utilities/text_style_custom.dart';
import 'package:shortzz/utilities/theme_res.dart';
import 'package:url_launcher/url_launcher.dart';

/// Asks people on an old build to update, using two build numbers set in
/// admin Settings once a release is live on Play. Below the latest: a sheet
/// they can put off, shown once per release. Below the minimum: one that
/// only leads to the Play Store, for a build that no longer works.
///
/// Builds before 1.3.0 (28) have none of this; a push reaches them.
class AppUpdatePrompt {
  AppUpdatePrompt._();

  static const String _putOffKey = 'update_prompt_put_off_version';
  static bool _checked = false;

  /// Once per app start, a moment after the dashboard opens.
  static Future<void> check() async {
    if (_checked || !Platform.isAndroid) return;
    _checked = true;
    final Setting? setting = SessionManager.instance.getSettings();
    final int latest = setting?.androidLatestVersion ?? 0;
    final int minimum = setting?.androidMinVersion ?? 0;
    if (latest <= 0 && minimum <= 0) return;

    final PackageInfo info;
    try {
      info = await PackageInfo.fromPlatform();
    } catch (e) {
      Loggers.error('Update check: no package info: $e');
      return;
    }
    final int build = int.tryParse(info.buildNumber) ?? 0;
    if (build <= 0) return;

    final bool required = build < minimum;
    if (!required) {
      if (build >= latest) return;
      final int? putOff =
          SessionManager.instance.storage.read<int>(_putOffKey);
      if (putOff == latest) return;
    }

    await Get.bottomSheet(
      _AppUpdateSheet(
        required: required,
        onUpdate: () => _openStore(info.packageName),
        onPutOff: () {
          SessionManager.instance.storage.write(_putOffKey, latest);
          Get.back();
        },
      ),
      isDismissible: !required,
      enableDrag: !required,
      isScrollControlled: true,
    );
  }

  static Future<void> _openStore(String packageName) async {
    // The Play Store app itself; the web page if it is missing.
    for (final String url in [
      'market://details?id=$packageName',
      'https://play.google.com/store/apps/details?id=$packageName',
    ]) {
      try {
        if (await launchUrl(Uri.parse(url),
            mode: LaunchMode.externalApplication)) {
          return;
        }
      } catch (e) {
        Loggers.error('Could not open $url: $e');
      }
    }
  }
}

class _AppUpdateSheet extends StatelessWidget {
  final bool required;
  final VoidCallback onUpdate;
  final VoidCallback onPutOff;

  const _AppUpdateSheet(
      {required this.required, required this.onUpdate, required this.onPutOff});

  @override
  Widget build(BuildContext context) {
    return PopScope(
      // A build that no longer works has nothing to go back to.
      canPop: !required,
      child: Wrap(
        children: [
          Container(
            width: double.infinity,
            decoration: ShapeDecoration(
                shape: const SmoothRectangleBorder(
                    borderRadius: SmoothBorderRadius.vertical(
                        top: SmoothRadius(cornerRadius: 40, cornerSmoothing: 1))),
                color: whitePure(context)),
            child: SafeArea(
              top: false,
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 20.0),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Center(
                      child: Container(
                          height: 1,
                          width: 100,
                          color: bgGrey(context),
                          margin: const EdgeInsets.only(top: 10)),
                    ),
                    const SizedBox(height: 20),
                    Text(
                        required
                            ? LKey.updateRequiredTitle.tr
                            : LKey.updateAvailableTitle.tr,
                        style: TextStyleCustom.unboundedMedium500(
                            color: textDarkGrey(context), fontSize: 15)),
                    const SizedBox(height: 20),
                    Text(
                        required
                            ? LKey.updateRequiredDescription.tr
                            : LKey.updateAvailableDescription.tr,
                        style: TextStyleCustom.outFitLight300(
                            fontSize: 16, color: textLightGrey(context))),
                    const SizedBox(height: 40),
                    TextButtonCustom(
                      onTap: onUpdate,
                      title: LKey.updateNow.tr,
                      backgroundColor: textDarkGrey(context),
                      margin: EdgeInsets.zero,
                      titleColor: whitePure(context),
                    ),
                    if (!required) ...[
                      const SizedBox(height: 10),
                      Center(
                        child: TextButton(
                          onPressed: onPutOff,
                          child: Text(LKey.notNow.tr,
                              style: TextStyleCustom.outFitRegular400(
                                  fontSize: 15,
                                  color: textLightGrey(context))),
                        ),
                      ),
                    ],
                    const SizedBox(height: 20),
                  ],
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

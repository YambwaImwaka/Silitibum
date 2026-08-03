import 'package:flutter/material.dart';
import 'package:flutter_widget_from_html_core/flutter_widget_from_html_core.dart';
import 'package:get/get.dart';
import 'package:shortzz/common/manager/session_manager.dart';
import 'package:shortzz/common/service/api/common_service.dart';
import 'package:shortzz/common/widget/custom_app_bar.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/utilities/text_style_custom.dart';
import 'package:shortzz/utilities/theme_res.dart';

enum TermAndPrivacyType {
  privacyPolicy,
  termAndCondition;

  String get title {
    switch (this) {
      case TermAndPrivacyType.privacyPolicy:
        return LKey.privacyPolicy;
      case TermAndPrivacyType.termAndCondition:
        return LKey.termsOfUse;
    }
  }
}

class TermAndPrivacyScreen extends StatefulWidget {
  final TermAndPrivacyType type;

  const TermAndPrivacyScreen({super.key, required this.type});

  @override
  State<TermAndPrivacyScreen> createState() => _TermAndPrivacyScreenState();
}

class _TermAndPrivacyScreenState extends State<TermAndPrivacyScreen> {
  final Rx<String?> _html = Rx(null);
  final RxBool _isLoading = true.obs;
  final RxBool _hasError = false.obs;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    _isLoading.value = true;
    _hasError.value = false;

    // These no longer ride along on the settings payload — the app asks for a
    // lite payload at startup and pulls the ~20KB of HTML only here. Fall
    // back to the cached settings copy so builds upgrading from a version
    // that still had them (and any legacy full payload) keep working offline.
    final cached = SessionManager.instance.getSettings();
    final cachedHtml = widget.type == TermAndPrivacyType.privacyPolicy
        ? cached?.privacyPolicy
        : cached?.termsOfUses;

    final docs = await CommonService.instance.fetchLegalDocs();
    final fetched = widget.type == TermAndPrivacyType.privacyPolicy
        ? docs?.privacyPolicy
        : docs?.termsOfUses;

    final resolved = (fetched?.isNotEmpty == true) ? fetched : cachedHtml;
    _html.value = resolved;
    _hasError.value = resolved == null || resolved.isEmpty;
    _isLoading.value = false;
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Column(
        children: [
          CustomAppBar(title: widget.type.title.tr),
          Expanded(
            child: Padding(
              padding: const EdgeInsets.all(15.0),
              child: MediaQuery.removePadding(
                context: context,
                removeTop: true, // Removes extra top padding
                child: Obx(
                  () {
                    if (_isLoading.value) {
                      return const Center(child: CircularProgressIndicator());
                    }
                    if (_hasError.value) {
                      return Center(
                        child: InkWell(
                          onTap: _load,
                          child: Column(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Icon(Icons.refresh, color: textDarkGrey(context), size: 36),
                              const SizedBox(height: 8),
                              Text(LKey.retry.tr,
                                  style: TextStyleCustom.outFitRegular400(
                                      fontSize: 15, color: textDarkGrey(context))),
                            ],
                          ),
                        ),
                      );
                    }
                    return HtmlWidget(
                      _html.value ?? '',
                      textStyle: TextStyleCustom.outFitRegular400(
                          fontSize: 15, color: textDarkGrey(context)),
                      renderMode: RenderMode.listView,
                    );
                  },
                ),
              ),
            ),
          )
        ],
      ),
    );
  }
}

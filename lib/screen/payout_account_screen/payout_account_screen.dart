import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:shortzz/common/widget/custom_app_bar.dart';
import 'package:shortzz/common/widget/custom_drop_down.dart';
import 'package:shortzz/common/widget/text_button_custom.dart';
import 'package:shortzz/common/widget/text_field_custom.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/model/gift_wallet/creator_status_model.dart';
import 'package:shortzz/screen/payout_account_screen/payout_account_screen_controller.dart';
import 'package:shortzz/utilities/color_res.dart';
import 'package:shortzz/utilities/text_style_custom.dart';
import 'package:shortzz/utilities/theme_res.dart';

/// Where a creator's withdrawals are sent: one mobile-money number in their
/// own name.
class PayoutAccountScreen extends StatelessWidget {
  const PayoutAccountScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final controller = Get.put(PayoutAccountScreenController());
    return Scaffold(
      body: Column(
        children: [
          CustomAppBar(title: LKey.payoutAccount.tr, showSearch: false),
          Expanded(
            child: SingleChildScrollView(
              padding: const EdgeInsets.only(bottom: 40),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Obx(() {
                    final account = controller.account.value;
                    if (account == null) return const SizedBox();
                    return _CurrentAccount(account: account);
                  }),
                  Padding(
                    padding: const EdgeInsets.fromLTRB(20, 15, 20, 10),
                    child: Obx(() => Text(
                          controller.account.value == null
                              ? LKey.addPayoutAccountDescription.tr
                              : '${LKey.changePayoutAccount.tr}. ${LKey.payoutChangeHoldNotice.trParams({'hours': '${controller.holdHours}'})}',
                          style: TextStyleCustom.outFitLight300(
                              color: textLightGrey(context), fontSize: 15),
                        )),
                  ),
                  TextFieldCustom(
                    controller: controller.legalNameController,
                    title: LKey.legalName.tr,
                    hintText: LKey.legalNameHint.tr,
                    keyboardType: TextInputType.name,
                  ),
                  Padding(
                    padding: const EdgeInsets.fromLTRB(20, 4, 20, 5),
                    child: Text(LKey.mobileNetwork.tr,
                        style: TextStyleCustom.outFitRegular400(
                            color: textDarkGrey(context), fontSize: 17)),
                  ),
                  Obx(() {
                    final items = controller.operators.toList();
                    final selected = items.firstWhereOrNull(
                            (o) => o.key == controller.selectedOperator.value) ??
                        items.first;
                    return CustomDropDownBtn<PayoutOperator>(
                      items: items,
                      selectedValue: selected,
                      getTitle: (o) => o.label,
                      onChanged: (o) {
                        if (o != null) controller.selectedOperator.value = o.key;
                      },
                      bgColor: bgLightGrey(context),
                      padding: const EdgeInsets.symmetric(horizontal: 20),
                      isExpanded: true,
                      height: 48,
                      style: TextStyleCustom.outFitLight300(
                          color: textDarkGrey(context), fontSize: 17),
                    );
                  }),
                  const SizedBox(height: 10),
                  TextFieldCustom(
                    controller: controller.phoneController,
                    title: LKey.mobileMoneyNumber.tr,
                    hintText: LKey.mobileMoneyNumberHint.tr,
                    keyboardType: TextInputType.phone,
                    onChanged: controller.onPhoneChanged,
                    inputFormatters: [
                      FilteringTextInputFormatter.allow(RegExp(r'[0-9+ ]')),
                      LengthLimitingTextInputFormatter(16),
                    ],
                  ),
                  Obx(() => CheckboxListTile(
                        value: controller.isAdult.value,
                        onChanged: (v) => controller.isAdult.value = v ?? false,
                        controlAffinity: ListTileControlAffinity.leading,
                        activeColor: themeAccentSolid(context),
                        title: Text(LKey.iAm18OrOlder.tr,
                            style: TextStyleCustom.outFitRegular400(
                                color: textDarkGrey(context), fontSize: 16)),
                      )),
                  const SizedBox(height: 20),
                  TextButtonCustom(
                    onTap: controller.save,
                    title: LKey.save.tr,
                    horizontalMargin: 15,
                    backgroundColor: textDarkGrey(context),
                    titleColor: whitePure(context),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _CurrentAccount extends StatelessWidget {
  final PayoutAccount account;

  const _CurrentAccount({required this.account});

  @override
  Widget build(BuildContext context) {
    final (String badge, Color color) = account.isSuspended
        ? (LKey.payoutAccountSuspended.tr, ColorRes.likeRed)
        : switch (account.nameMatch) {
            NameMatch.match => (LKey.nameVerified.tr, ColorRes.green),
            NameMatch.mismatch => account.nameOverride
                ? (LKey.nameMismatchApproved.tr, ColorRes.green)
                : (LKey.nameMismatchWarning.tr, ColorRes.likeRed),
            NameMatch.unverified => (LKey.nameNotVerified.tr, textLightGrey(context)),
          };
    return Container(
      width: double.infinity,
      margin: const EdgeInsets.fromLTRB(15, 15, 15, 0),
      padding: const EdgeInsets.all(15),
      decoration: BoxDecoration(
          color: bgLightGrey(context), borderRadius: BorderRadius.circular(12)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(LKey.currentPayoutAccount.tr,
              style: TextStyleCustom.outFitLight300(color: textLightGrey(context))),
          const SizedBox(height: 4),
          Text(account.legalName ?? '',
              style: TextStyleCustom.outFitMedium500(color: textDarkGrey(context), fontSize: 17)),
          Text('${account.payoutOperatorLabel ?? ''} · ${account.payoutPhoneMasked ?? ''}',
              style: TextStyleCustom.outFitRegular400(color: textDarkGrey(context), fontSize: 15)),
          const SizedBox(height: 6),
          Text(badge, style: TextStyleCustom.outFitRegular400(color: color, fontSize: 14)),
        ],
      ),
    );
  }
}

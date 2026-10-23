import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:intl/intl.dart';
import 'package:shortzz/common/functions/money_format.dart';
import 'package:shortzz/common/widget/custom_app_bar.dart';
import 'package:shortzz/common/widget/loader_widget.dart';
import 'package:shortzz/common/widget/text_button_custom.dart';
import 'package:shortzz/common/widget/text_field_custom.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/model/gift_wallet/creator_status_model.dart';
import 'package:shortzz/screen/payout_account_screen/payout_account_screen.dart';
import 'package:shortzz/screen/withdraw_earnings_screen/withdraw_earnings_screen_controller.dart';
import 'package:shortzz/utilities/color_res.dart';
import 'package:shortzz/utilities/style_res.dart';
import 'package:shortzz/utilities/text_style_custom.dart';
import 'package:shortzz/utilities/theme_res.dart';

/// Withdraw creator earnings (wallet v2) to the registered payout account.
class WithdrawEarningsScreen extends StatelessWidget {
  const WithdrawEarningsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final controller = Get.put(WithdrawEarningsScreenController());
    return Scaffold(
      body: Column(
        children: [
          CustomAppBar(title: LKey.withdrawEarnings.tr, showSearch: false),
          Expanded(
            child: RefreshIndicator(
              onRefresh: controller.fetchStatus,
              child: SingleChildScrollView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.only(bottom: 40),
                child: Obx(() {
                  final status = controller.status.value;
                  final String currency = controller.currency;
                  final String? blocked = controller.blockedReason;
                  return Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Container(
                        margin: const EdgeInsets.all(15),
                        padding: const EdgeInsets.all(20),
                        decoration: BoxDecoration(
                            gradient: StyleRes.themeGradient,
                            borderRadius: BorderRadius.circular(16)),
                        child: Column(
                          children: [
                            Text(
                                formatCents(controller.availableCents,
                                    currency: currency),
                                style: TextStyleCustom.unboundedExtraBold800(
                                    color: whitePure(context), fontSize: 30)),
                            Text(LKey.availableToWithdraw.tr,
                                style: TextStyleCustom.outFitLight300(
                                    color: whitePure(context)
                                        .withValues(alpha: .85),
                                    fontSize: 15)),
                            const SizedBox(height: 6),
                            Text(
                                LKey.minimumWithdrawalAmount.trParams({
                                  'amount': formatCents(controller.minCents,
                                      currency: currency)
                                }),
                                style: TextStyleCustom.outFitRegular400(
                                    color: whitePure(context), fontSize: 14)),
                          ],
                        ),
                      ),
                      if (status == null && controller.isLoadingStatus.value)
                        const Padding(
                            padding: EdgeInsets.all(20), child: LoaderWidget())
                      else ...[
                        if (blocked != null &&
                            blocked != LKey.completeChecklistFirst.tr)
                          _Notice(text: blocked),
                        _SendTo(
                          account: status?.payoutAccount,
                          onChange: () async {
                            await Get.to(() => const PayoutAccountScreen());
                            controller.fetchStatus();
                          },
                        ),
                        if (status?.eligibility case final eligibility?
                            when !eligibility.eligible)
                          _Checklist(checks: eligibility.checks),
                      ],
                      const SizedBox(height: 15),
                      TextFieldCustom(
                        controller: controller.amountController,
                        title:
                            LKey.amountInCurrency.trParams({'currency': currency}),
                        hintText: LKey.enterAmountHint.tr,
                        keyboardType:
                            const TextInputType.numberWithOptions(decimal: true),
                        inputFormatters: [
                          FilteringTextInputFormatter.allow(
                              RegExp(r'^\d{0,9}\.?\d{0,2}')),
                        ],
                      ),
                      Align(
                        alignment: AlignmentDirectional.centerEnd,
                        child: InkWell(
                          onTap: controller.fillAvailable,
                          child: Padding(
                            padding: const EdgeInsets.symmetric(
                                horizontal: 20, vertical: 2),
                            child: Text(LKey.withdrawAll.tr,
                                style: TextStyleCustom.outFitRegular400(
                                    color: themeAccentSolid(context),
                                    fontSize: 15)),
                          ),
                        ),
                      ),
                      Padding(
                        padding: const EdgeInsets.fromLTRB(20, 10, 20, 20),
                        child: Text(
                          LKey.withdrawalsPerWeekNotice
                              .trParams({'count': '${controller.perWeek}'}),
                          style: TextStyleCustom.outFitLight300(
                              color: textLightGrey(context), fontSize: 14),
                        ),
                      ),
                      TextButtonCustom(
                        onTap: controller.submit,
                        title: LKey.withdraw.tr,
                        horizontalMargin: 15,
                        backgroundColor: blocked == null
                            ? textDarkGrey(context)
                            : textLightGrey(context),
                        titleColor: whitePure(context),
                      ),
                    ],
                  );
                }),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _Notice extends StatelessWidget {
  final String text;

  const _Notice({required this.text});

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.fromLTRB(15, 0, 15, 12),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
          color: ColorRes.orange.withValues(alpha: .12),
          borderRadius: BorderRadius.circular(10)),
      child: Text(text,
          style: TextStyleCustom.outFitRegular400(
              color: textDarkGrey(context), fontSize: 15)),
    );
  }
}

class _SendTo extends StatelessWidget {
  final PayoutAccount? account;
  final VoidCallback onChange;

  const _SendTo({required this.account, required this.onChange});

  @override
  Widget build(BuildContext context) {
    final PayoutAccount? account = this.account;
    return Container(
      margin: const EdgeInsets.symmetric(horizontal: 15),
      padding: const EdgeInsets.all(15),
      decoration: BoxDecoration(
          color: bgLightGrey(context), borderRadius: BorderRadius.circular(12)),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(LKey.sendTo.tr,
                    style: TextStyleCustom.outFitLight300(
                        color: textLightGrey(context))),
                const SizedBox(height: 4),
                if (account == null)
                  Text(LKey.noPayoutAccountYet.tr,
                      style: TextStyleCustom.outFitMedium500(
                          color: textDarkGrey(context), fontSize: 16))
                else ...[
                  Text(
                      '${account.payoutOperatorLabel ?? ''} · ${account.payoutPhoneMasked ?? ''}',
                      style: TextStyleCustom.outFitMedium500(
                          color: textDarkGrey(context), fontSize: 16)),
                  if ((account.legalName ?? '').isNotEmpty)
                    Text(account.legalName!,
                        style: TextStyleCustom.outFitRegular400(
                            color: textLightGrey(context), fontSize: 14)),
                ],
              ],
            ),
          ),
          InkWell(
            onTap: onChange,
            child: Padding(
              padding: const EdgeInsets.all(5),
              child: Text(
                  account == null ? LKey.addPayoutAccount.tr : LKey.change.tr,
                  style: TextStyleCustom.outFitMedium500(
                      color: themeAccentSolid(context), fontSize: 15)),
            ),
          ),
        ],
      ),
    );
  }
}

class _Checklist extends StatelessWidget {
  final List<EligibilityCheck> checks;

  const _Checklist({required this.checks});

  @override
  Widget build(BuildContext context) {
    final DateFormat format = DateFormat('dd MMM, hh:mm a');
    return Container(
      margin: const EdgeInsets.fromLTRB(15, 12, 15, 0),
      padding: const EdgeInsets.all(15),
      decoration: BoxDecoration(
          color: bgLightGrey(context), borderRadius: BorderRadius.circular(12)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(LKey.beforeYouCanWithdraw.tr,
              style: TextStyleCustom.outFitMedium500(
                  color: textDarkGrey(context), fontSize: 16)),
          const SizedBox(height: 8),
          for (final check in checks)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 4),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Icon(
                      check.ok
                          ? Icons.check_circle_rounded
                          : Icons.radio_button_unchecked_rounded,
                      size: 20,
                      color: check.ok ? ColorRes.green : textLightGrey(context)),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(check.label ?? '',
                            style: TextStyleCustom.outFitRegular400(
                                color: textDarkGrey(context), fontSize: 15)),
                        if (!check.ok && check.availableAt != null)
                          Text(
                              LKey.availableFrom.trParams({
                                'date':
                                    format.format(check.availableAt!.toLocal())
                              }),
                              style: TextStyleCustom.outFitLight300(
                                  color: textLightGrey(context), fontSize: 13)),
                      ],
                    ),
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}

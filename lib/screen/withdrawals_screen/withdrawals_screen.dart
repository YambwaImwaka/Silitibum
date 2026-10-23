import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:shortzz/common/extensions/common_extension.dart';
import 'package:shortzz/common/extensions/string_extension.dart';
import 'package:shortzz/common/functions/json_parse.dart';
import 'package:shortzz/common/functions/money_format.dart';
import 'package:shortzz/common/manager/session_manager.dart';
import 'package:shortzz/common/widget/custom_app_bar.dart';
import 'package:shortzz/common/widget/loader_widget.dart';
import 'package:shortzz/common/widget/no_data_widget.dart';
import 'package:shortzz/common/widget/text_button_custom.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/model/gift_wallet/withdraw_model.dart';
import 'package:shortzz/screen/withdrawals_screen/withdrawals_screen_controller.dart';
import 'package:shortzz/utilities/app_res.dart';
import 'package:shortzz/utilities/color_res.dart';
import 'package:shortzz/utilities/text_style_custom.dart';
import 'package:shortzz/utilities/theme_res.dart';

class WithdrawalsScreen extends StatelessWidget {
  const WithdrawalsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final controller = Get.put(WithdrawalsScreenController());
    final String currency = SessionManager.instance.getCurrency();
    return Scaffold(
      body: Column(
        children: [
          CustomAppBar(title: LKey.withdrawals.tr),
          Expanded(child: Obx(
            () {
              return controller.isLoading.value && controller.withdraws.isEmpty
                  ? const LoaderWidget()
                  : NoDataView(
                      showShow: !controller.isLoading.value &&
                          controller.withdraws.isEmpty,
                      child: ListView.builder(
                        itemCount: controller.withdraws.length,
                        padding: const EdgeInsets.only(top: 1),
                        itemBuilder: (context, index) {
                          Withdraw withdraw = controller.withdraws[index];
                          Color statusColor = withdraw.statusColor;
                          final String reason = withdraw.reason?.trim() ?? '';
                          final String createdAt = withdraw.createdAt ?? '';
                          return Container(
                            color: bgLightGrey(context),
                            margin: const EdgeInsets.symmetric(vertical: 1),
                            child: Column(
                              children: [
                                Padding(
                                  padding: const EdgeInsets.symmetric(
                                      horizontal: 20.0, vertical: 8),
                                  child: Row(
                                    children: [
                                      Expanded(
                                        child: Column(
                                          crossAxisAlignment:
                                              CrossAxisAlignment.start,
                                          children: [
                                            Text(
                                              '${AppRes.hash}${withdraw.requestNumber}',
                                              style: TextStyleCustom
                                                  .unboundedSemiBold600(
                                                      color: textDarkGrey(
                                                          context)),
                                            ),
                                            Text(
                                              '${withdraw.gateway} : ${withdraw.account}',
                                              style: TextStyleCustom
                                                  .outFitLight300(
                                                      color: textLightGrey(
                                                          context),
                                                      fontSize: 13),
                                            ),
                                            if (createdAt.isNotEmpty)
                                              Text(
                                                createdAt.formatDate1,
                                                style: TextStyleCustom
                                                    .outFitLight300(
                                                        color: textLightGrey(
                                                            context),
                                                        fontSize: 13),
                                              ),
                                            // Why an admin rejected it, or
                                            // why the payout failed.
                                            if (reason.isNotEmpty)
                                              Padding(
                                                padding: const EdgeInsets.only(
                                                    top: 3),
                                                child: Text(
                                                  '${LKey.reason.tr}: $reason',
                                                  style: TextStyleCustom
                                                      .outFitRegular400(
                                                          color: statusColor,
                                                          fontSize: 13),
                                                ),
                                              ),
                                          ],
                                        ),
                                      ),
                                      Column(
                                        children: [
                                          Text(
                                            formatMoney(
                                                asDoubleOrNull(
                                                    withdraw.amount),
                                                currency: currency),
                                            style:
                                                TextStyleCustom.outFitBold700(
                                                    fontSize: 18,
                                                    color:
                                                        textDarkGrey(context)),
                                          ),
                                          const SizedBox(height: 5),
                                          TextButtonCustom(
                                            onTap: () {},
                                            title: withdraw.statusTitle,
                                            btnHeight: 23,
                                            horizontalMargin: 0,
                                            radius: 5,
                                            fontSize: 12,
                                            padding: const EdgeInsets.symmetric(
                                                horizontal: 12),
                                            backgroundColor: statusColor
                                                .withValues(alpha: .15),
                                            titleColor: statusColor,
                                          )
                                        ],
                                      ),
                                    ],
                                  ),
                                ),
                                // Coin conversion details only exist on
                                // legacy coin cash-outs; an earnings
                                // withdrawal has no coins.
                                if ((withdraw.coins ?? 0) > 0)
                                  Container(
                                    color: bgGrey(context),
                                    height: 29,
                                    width: double.infinity,
                                    padding: const EdgeInsets.symmetric(
                                        horizontal: 20),
                                    alignment: AlignmentDirectional.centerStart,
                                    child: Text(
                                        '${(withdraw.coins?.toInt() ?? 0).numberFormat} ${LKey.coins.tr} '
                                        ':'
                                        ' ${(withdraw.coinValue ?? 0).currencyFormat} / ${LKey.coin.tr}',
                                        style: TextStyleCustom.outFitLight300(
                                            color: textLightGrey(context))),
                                  )
                              ],
                            ),
                          );
                        },
                      ),
                    );
            },
          ))
        ],
      ),
    );
  }
}

/// 0 pending, 1 completed, 2 rejected, 3 processing (async provider call,
/// awaiting confirmation), 4 failed (provider call failed, money refunded).
/// An explicit switch — the old nested ternary silently rendered any
/// unrecognized status as "rejected".
extension WithdrawStatusX on Withdraw {
  String get statusTitle => switch (status) {
        0 => LKey.pending.tr,
        1 => LKey.completed.tr,
        3 => LKey.processing.tr,
        4 => LKey.withdrawalFailed.tr,
        _ => LKey.rejected.tr,
      };

  Color get statusColor => switch (status) {
        0 => ColorRes.orange,
        1 => ColorRes.green,
        3 => ColorRes.infoBlue,
        _ => ColorRes.likeRed,
      };
}

import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:shortzz/common/functions/money_format.dart';
import 'package:shortzz/common/manager/session_manager.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/screen/coin_wallet_screen/coin_wallet_screen_controller.dart';
import 'package:shortzz/screen/coin_wallet_screen/widget/coin_wallet_list.dart';
import 'package:shortzz/screen/coin_wallet_screen/widget/coin_wallet_top_view.dart';
import 'package:shortzz/screen/earnings_screen/earnings_screen.dart';
import 'package:shortzz/utilities/text_style_custom.dart';
import 'package:shortzz/utilities/theme_res.dart';

class CoinWalletScreen extends StatelessWidget {
  const CoinWalletScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final controller = Get.put(CoinWalletScreenController());
    return Scaffold(
      body: Column(
        children: [
          const CoinWalletTopView(),
          if (controller.settings?.isWalletV2 ?? false)
            _EarningsStrip(controller: controller),
          const SizedBox(height: 15),
          Text(LKey.coinShop.tr,
              style: TextStyleCustom.unboundedRegular400(
                color: textDarkGrey(context),
                fontSize: 17,
              )),
          const SizedBox(height: 5),
          Text(LKey.rechargeWallet.tr,
              style: TextStyleCustom.outFitLight300(color: textLightGrey(context), fontSize: 17),
              textAlign: TextAlign.center),
          CoinWalletList(controller: controller)
        ],
      ),
    );
  }
}

/// Wallet v2: coins only buy gifts; what a creator can withdraw is their
/// earnings, one tap away.
class _EarningsStrip extends StatelessWidget {
  final CoinWalletScreenController controller;

  const _EarningsStrip({required this.controller});

  @override
  Widget build(BuildContext context) {
    final int share =
        SessionManager.instance.getSettings()?.creatorSharePercent ?? 60;
    return InkWell(
      onTap: () => Get.to(() => const EarningsScreen())?.then((_) {
        controller.fetchData();
      }),
      child: Container(
        margin: const EdgeInsets.fromLTRB(15, 10, 15, 0),
        padding: const EdgeInsets.fromLTRB(15, 12, 8, 12),
        decoration: BoxDecoration(
            color: bgLightGrey(context),
            borderRadius: BorderRadius.circular(12)),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(LKey.earnings.tr,
                      style: TextStyleCustom.outFitMedium500(
                          color: textDarkGrey(context), fontSize: 16)),
                  Text(LKey.youKeepShareOfGifts.trParams({'share': '$share'}),
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyleCustom.outFitLight300(
                          color: textLightGrey(context), fontSize: 13)),
                ],
              ),
            ),
            const SizedBox(width: 10),
            Obx(() => Column(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: [
                    Text(
                        formatMoney(controller.myUser.value?.earningsAvailable,
                            currency: SessionManager.instance.getCurrency()),
                        style: TextStyleCustom.unboundedMedium500(
                            color: textDarkGrey(context), fontSize: 15)),
                    Text(LKey.availableToWithdraw.tr,
                        style: TextStyleCustom.outFitLight300(
                            color: textLightGrey(context), fontSize: 12)),
                  ],
                )),
            Icon(Icons.chevron_right_rounded, color: textLightGrey(context)),
          ],
        ),
      ),
    );
  }
}

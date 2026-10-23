import 'package:figma_squircle_updated/figma_squircle.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:shortzz/common/functions/web_payment.dart';
import 'package:shortzz/common/widget/text_button_custom.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/screen/coin_wallet_screen/coin_wallet_screen_controller.dart';
import 'package:shortzz/utilities/asset_res.dart';
import 'package:shortzz/utilities/text_style_custom.dart';
import 'package:shortzz/utilities/theme_res.dart';

class CoinWalletList extends StatelessWidget {
  final CoinWalletScreenController controller;

  const CoinWalletList({super.key, required this.controller});

  @override
  Widget build(BuildContext context) {
    return Expanded(
      child: Obx(
        () {
          return Column(
            children: [
              Expanded(
                child: controller.coinPlans.isEmpty
                    ? Center(
                        child: Padding(
                          padding: const EdgeInsets.all(30),
                          child: Text(LKey.coinPurchasesUnavailable.tr,
                              textAlign: TextAlign.center,
                              style: TextStyleCustom.outFitRegular400(
                                  color: textLightGrey(context), fontSize: 16)),
                        ),
                      )
                    : ListView.builder(
                        padding: const EdgeInsets.only(top: 20),
                        itemCount: controller.coinPlans.length,
                        physics: const ClampingScrollPhysics(),
                        itemBuilder: (context, index) {
                          CoinPlan data = controller.coinPlans[index];
                          return Container(
                            height: 70,
                            width: double.infinity,
                            padding:
                                const EdgeInsets.symmetric(horizontal: 12),
                            margin: const EdgeInsets.symmetric(
                                horizontal: 10, vertical: 5),
                            decoration: ShapeDecoration(
                                shape: SmoothRectangleBorder(
                                    borderRadius: SmoothBorderRadius(
                                        cornerRadius: 10, cornerSmoothing: 1),
                                    side: BorderSide(
                                      color: textLightGrey(context)
                                          .withValues(alpha: .2),
                                    )),
                                color: bgLightGrey(context)),
                            child: Row(
                              children: [
                                Image.asset(AssetRes.icCoin,
                                    width: 34, height: 34),
                                const SizedBox(width: 10),
                                Expanded(
                                    child: Column(
                                  crossAxisAlignment:
                                      CrossAxisAlignment.start,
                                  mainAxisSize: MainAxisSize.min,
                                  children: [
                                    Text('${data.coin} ${LKey.coins.tr}',
                                        style: TextStyleCustom
                                            .unboundedMedium500(
                                                color:
                                                    textDarkGrey(context),
                                                fontSize: 15)),
                                    Text(
                                        '${data.priceString} ${LKey.only.tr}',
                                        style:
                                            TextStyleCustom.outFitRegular400(
                                                color: textLightGrey(
                                                    context))),
                                  ],
                                )),
                                TextButtonCustom(
                                    onTap: () => controller.onPurchase(data),
                                    title: LKey.purchase.tr,
                                    backgroundColor:
                                        themeAccentSolid(context),
                                    btnHeight: 40,
                                    fontSize: 15,
                                    titleColor: whitePure(context),
                                    padding: const EdgeInsets.symmetric(
                                        horizontal: 18),
                                    horizontalMargin: 0)
                              ],
                            ),
                          );
                        },
                      ),
              ),
              // Mobile-money top-up: opens the backend's public /topup page in
              // the browser, signed in through a single-use link (Google and
              // Apple accounts have no password for its own form). Deliberately
              // outside the app's IAP flow — the page credits the wallet via
              // webhook. Store-policy note: linking out for consumable coin
              // purchases can violate Play/App Store rules; accepted by the
              // owner.
              SafeArea(
                top: false,
                child: TextButtonCustom(
                    onTap: () => openWebPayment(WebPaymentPage.topUp,
                        onReturn: (user) {
                      if (user != null) controller.myUser.value = user;
                    }),
                    title: LKey.topUpWithMobileMoney.tr,
                    backgroundColor: themeAccentSolid(context),
                    btnHeight: 48,
                    fontSize: 15,
                    titleColor: whitePure(context),
                    padding: const EdgeInsets.symmetric(horizontal: 18),
                    horizontalMargin: 10),
              ),
              const SizedBox(height: 10),
            ],
          );
        },
      ),
    );
  }
}

import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:shortzz/common/extensions/string_extension.dart';
import 'package:shortzz/common/functions/money_format.dart';
import 'package:shortzz/common/widget/custom_app_bar.dart';
import 'package:shortzz/common/widget/custom_image.dart';
import 'package:shortzz/common/widget/loader_widget.dart';
import 'package:shortzz/common/widget/text_button_custom.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/model/gift_wallet/wallet_transaction_model.dart';
import 'package:shortzz/screen/earnings_screen/earnings_screen_controller.dart';
import 'package:shortzz/screen/payout_account_screen/payout_account_screen.dart';
import 'package:shortzz/screen/withdraw_earnings_screen/withdraw_earnings_screen.dart';
import 'package:shortzz/screen/withdrawals_screen/withdrawals_screen.dart';
import 'package:shortzz/utilities/asset_res.dart';
import 'package:shortzz/utilities/color_res.dart';
import 'package:shortzz/utilities/style_res.dart';
import 'package:shortzz/utilities/text_style_custom.dart';
import 'package:shortzz/utilities/theme_res.dart';

/// Creator earnings (wallet v2): what is withdrawable now, what is still on
/// hold, where payouts go, and every movement of money.
class EarningsScreen extends StatelessWidget {
  const EarningsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final controller = Get.put(EarningsScreenController());
    return Scaffold(
      body: Column(
        children: [
          CustomAppBar(title: LKey.earnings.tr, showSearch: false),
          Expanded(
            child: RefreshIndicator(
              onRefresh: controller.refreshAll,
              child: Obx(() {
                final rows = controller.transactions;
                return ListView.builder(
                  controller: controller.scrollController,
                  physics: const AlwaysScrollableScrollPhysics(),
                  padding: const EdgeInsets.only(bottom: 30),
                  itemCount: rows.length + 2,
                  itemBuilder: (context, index) {
                    if (index == 0) {
                      return _Summary(controller: controller);
                    }
                    if (index == rows.length + 1) {
                      if (controller.isLoadingHistory.value) {
                        return const Padding(
                            padding: EdgeInsets.all(20), child: LoaderWidget());
                      }
                      if (rows.isEmpty) {
                        return Padding(
                          padding: const EdgeInsets.all(30),
                          child: Column(children: [
                            Text(LKey.noTransactionsYet.tr,
                                style: TextStyleCustom.outFitMedium500(
                                    color: textDarkGrey(context), fontSize: 16)),
                            const SizedBox(height: 5),
                            Text(LKey.noTransactionsDescription.tr,
                                textAlign: TextAlign.center,
                                style: TextStyleCustom.outFitLight300(
                                    color: textLightGrey(context))),
                          ]),
                        );
                      }
                      return const SizedBox();
                    }
                    return _TransactionRow(
                        tx: rows[index - 1], currency: controller.currency);
                  },
                );
              }),
            ),
          ),
        ],
      ),
    );
  }
}

class _Summary extends StatelessWidget {
  final EarningsScreenController controller;

  const _Summary({required this.controller});

  @override
  Widget build(BuildContext context) {
    return Obx(() {
      final wallet = controller.wallet.value;
      final currency = controller.currency;
      final holdDays = controller.holdDays;
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
                Text(formatMoney(wallet.earningsAvailable, currency: currency),
                    style: TextStyleCustom.unboundedExtraBold800(
                        color: whitePure(context), fontSize: 32)),
                Text(LKey.availableToWithdraw.tr,
                    style: TextStyleCustom.outFitLight300(
                        color: whitePure(context).withValues(alpha: .85),
                        fontSize: 15)),
                const SizedBox(height: 14),
                Row(
                  children: [
                    Expanded(
                      child: _Figure(
                        value: formatMoney(wallet.earningsOnHold, currency: currency),
                        label:
                            '${LKey.onHold.tr} · ${holdDays == 1 ? LKey.availableAfterOneDay.tr : LKey.availableAfterDays.trParams({'days': '$holdDays'})}',
                      ),
                    ),
                    Container(height: 30, width: .5, color: whitePure(context)),
                    Expanded(
                      child: _Figure(
                        value: formatMoney(wallet.earningsLifetime, currency: currency),
                        label: LKey.lifetime.tr,
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 20),
            child: Text(
              LKey.youKeepShareOfGifts
                  .trParams({'share': '${controller.sharePercent}'}),
              textAlign: TextAlign.center,
              style: TextStyleCustom.outFitRegular400(
                  color: textDarkGrey(context), fontSize: 15),
            ),
          ),
          const SizedBox(height: 12),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 15),
            child: Row(
              children: [
                Expanded(
                  child: TextButtonCustom(
                    onTap: () async {
                      await Get.to(() => const WithdrawEarningsScreen());
                      controller.refreshAll();
                    },
                    title: LKey.withdraw.tr,
                    backgroundColor: themeAccentSolid(context),
                    titleColor: whitePure(context),
                    btnHeight: 44,
                    horizontalMargin: 0,
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: TextButtonCustom(
                    onTap: () async {
                      await Get.to(() => const PayoutAccountScreen());
                      controller.fetchStatus();
                    },
                    title: LKey.payoutAccount.tr,
                    backgroundColor: bgGrey(context),
                    titleColor: textDarkGrey(context),
                    btnHeight: 44,
                    horizontalMargin: 0,
                  ),
                ),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 18, 20, 6),
            child: Row(
              children: [
                Text(LKey.walletHistory.tr,
                    style: TextStyleCustom.unboundedMedium500(
                        color: textDarkGrey(context), fontSize: 15)),
                const Spacer(),
                InkWell(
                  onTap: () => Get.to(() => const WithdrawalsScreen()),
                  child: Text(LKey.withdrawals.tr,
                      style: TextStyleCustom.outFitRegular400(
                          color: themeAccentSolid(context), fontSize: 15)),
                ),
              ],
            ),
          ),
        ],
      );
    });
  }
}

class _Figure extends StatelessWidget {
  final String value;
  final String label;

  const _Figure({required this.value, required this.label});

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Text(value,
            style: TextStyleCustom.unboundedMedium500(
                color: whitePure(context), fontSize: 15)),
        Text(label,
            textAlign: TextAlign.center,
            style: TextStyleCustom.outFitLight300(
                color: whitePure(context).withValues(alpha: .85), fontSize: 13)),
      ],
    );
  }
}

class _TransactionRow extends StatelessWidget {
  final WalletTransaction tx;
  final String currency;

  const _TransactionRow({required this.tx, required this.currency});

  String get _title {
    final String name = tx.counterparty?.username ?? '';
    switch (tx.type) {
      case WalletTransactionType.giftReceived:
        return name.isEmpty ? LKey.txGiftReceived.tr : LKey.txGiftFrom.trParams({'name': '@$name'});
      case WalletTransactionType.giftSent:
        return name.isEmpty ? LKey.txGiftSent.tr : LKey.txGiftSentTo.trParams({'name': '@$name'});
      case WalletTransactionType.withdrawal:
        return LKey.txWithdrawal.tr;
      case WalletTransactionType.withdrawalRefund:
        return LKey.txWithdrawalReturned.tr;
      case WalletTransactionType.purchaseTopup:
      case WalletTransactionType.purchaseIap:
        return LKey.txCoinsBought.tr;
      case WalletTransactionType.bonusRegistration:
        return LKey.txBonusCoins.tr;
      case WalletTransactionType.adminAdjustment:
        final String note = tx.note?.trim() ?? '';
        return note.isEmpty ? LKey.txAdjustment.tr : '${LKey.txAdjustment.tr}: $note';
      case WalletTransactionType.iapRefund:
        return LKey.txRefund.tr;
      case WalletTransactionType.creatorFund:
        return LKey.txCreatorFund.tr;
      case WalletTransactionType.migrationOpening:
        return LKey.txOpeningBalance.tr;
      case WalletTransactionType.giftReversed:
        return LKey.txGiftRefunded.tr;
      case WalletTransactionType.unknown:
        return LKey.txOther.tr;
    }
  }

  String get _subtitle {
    final List<String> parts = [];
    final String description = tx.post?.description?.trim() ?? '';
    if (description.isNotEmpty) {
      parts.add(description);
    } else if (tx.livestreamId != null) {
      parts.add('Live');
    }
    final String created = tx.createdAt?.toLocal().toIso8601String() ?? '';
    if (created.isNotEmpty) parts.add(created.formatDate);
    return parts.join(' · ');
  }

  @override
  Widget build(BuildContext context) {
    final bool credit = tx.isCredit;
    final Color amountColor = credit ? ColorRes.green : textDarkGrey(context);
    final String amountText = tx.isCoinRow
        ? '${tx.coins > 0 ? '+' : ''}${LKey.coinsCount.trParams({'count': '${tx.coins}'})}'
        : formatMoney(tx.amount, currency: currency, signed: true);

    return Container(
      color: bgLightGrey(context),
      margin: const EdgeInsets.symmetric(vertical: .5),
      padding: const EdgeInsets.symmetric(horizontal: 15, vertical: 10),
      child: Row(
        children: [
          if (tx.gift?.image != null)
            CustomImage(image: tx.gift!.image!.addBaseURL(), size: const Size(40, 40), radius: 8)
          else if (tx.post?.thumbnail != null)
            CustomImage(image: tx.post!.thumbnail!.addBaseURL(), size: const Size(40, 40), radius: 8)
          else
            Image.asset(AssetRes.icCoin, width: 34, height: 34),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(_title,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyleCustom.outFitMedium500(
                        color: textDarkGrey(context), fontSize: 15)),
                if (_subtitle.isNotEmpty)
                  Text(_subtitle,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyleCustom.outFitLight300(
                          color: textLightGrey(context), fontSize: 13)),
              ],
            ),
          ),
          const SizedBox(width: 8),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(amountText,
                  style: TextStyleCustom.outFitMedium500(
                      color: amountColor, fontSize: 15)),
              if (tx.isCoinRow && tx.amount != 0)
                Text(formatMoney(tx.amount.abs(), currency: currency),
                    style: TextStyleCustom.outFitLight300(
                        color: textLightGrey(context), fontSize: 12)),
            ],
          ),
        ],
      ),
    );
  }
}

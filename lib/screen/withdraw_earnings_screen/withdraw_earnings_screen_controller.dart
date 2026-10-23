import 'package:flutter/widgets.dart';
import 'package:get/get.dart';
import 'package:shortzz/common/controller/base_controller.dart';
import 'package:shortzz/common/functions/money_format.dart';
import 'package:shortzz/common/manager/logger.dart';
import 'package:shortzz/common/manager/session_manager.dart';
import 'package:shortzz/common/service/api/gift_wallet_service.dart';
import 'package:shortzz/common/widget/confirmation_dialog.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/model/gift_wallet/creator_status_model.dart';
import 'package:shortzz/screen/withdrawals_screen/withdrawals_screen.dart';

/// Sends some of a creator's earnings (wallet v2) to their registered payout
/// account. The server re-checks every rule; the checks here only explain a
/// refusal before the round trip.
class WithdrawEarningsScreenController extends BaseController {
  final TextEditingController amountController = TextEditingController();
  final Rx<CreatorStatus?> status = Rx<CreatorStatus?>(null);
  final RxBool isLoadingStatus = true.obs;

  String get currency =>
      status.value?.currency ?? SessionManager.instance.getCurrency();

  int get availableCents =>
      parseCents(status.value?.wallet?.earningsAvailable ??
          SessionManager.instance.getUser()?.earningsAvailable) ??
      0;

  int get minCents =>
      parseCents(status.value?.minWithdrawalAmount ??
          SessionManager.instance.getSettings()?.minWithdrawalAmount) ??
      0;

  int get perWeek =>
      status.value?.maxWithdrawalsPerWeek ??
      SessionManager.instance.getSettings()?.maxWithdrawalsPerWeek ??
      1;

  /// Why a withdrawal can't be requested right now, or null when it can.
  /// Null until the status has loaded: the server has the final say anyway.
  String? get blockedReason {
    final s = status.value;
    if (s == null) return null;
    if (!s.withdrawalsEnabled) return LKey.withdrawalsPausedMessage.tr;
    final open = s.openWithdrawal;
    if (open != null) {
      return LKey.withdrawalInProgressMessage.trParams({
        'amount': formatCents(parseCents(open.amount) ?? 0, currency: currency),
        'status': open.statusTitle.toLowerCase(),
      });
    }
    if (s.payoutAccount == null) return LKey.addPayoutAccountFirst.tr;
    if (s.eligibility?.eligible == false) return LKey.completeChecklistFirst.tr;
    if (availableCents < minCents) {
      return LKey.notEnoughToWithdraw
          .trParams({'amount': formatCents(minCents, currency: currency)});
    }
    return null;
  }

  @override
  void onInit() {
    super.onInit();
    fetchStatus();
  }

  @override
  void onClose() {
    amountController.dispose();
    super.onClose();
  }

  Future<void> fetchStatus() async {
    isLoadingStatus.value = true;
    try {
      final response = await GiftWalletService.instance.fetchCreatorStatus();
      final data = response.data;
      if (response.status == true && data != null) {
        status.value = data;
        if (data.wallet != null) _cacheWallet(data.wallet!);
      }
    } catch (e) {
      Loggers.error('fetchCreatorStatus failed: $e');
    } finally {
      isLoadingStatus.value = false;
    }
  }

  void fillAvailable() {
    amountController.text = centsToAmountParam(availableCents);
  }

  void submit() {
    final String? blocked = blockedReason;
    if (blocked != null) {
      return showSnackBar(blocked, keepCase: true, second: 4);
    }
    final int? cents = parseCents(amountController.text.trim());
    if (cents == null || cents <= 0) {
      return showSnackBar(LKey.enterWithdrawalAmount.tr, keepCase: true);
    }
    if (cents < minCents) {
      return showSnackBar(
          LKey.amountBelowMinimum
              .trParams({'amount': formatCents(minCents, currency: currency)}),
          keepCase: true);
    }
    if (cents > availableCents) {
      return showSnackBar(
          LKey.amountAboveAvailable.trParams(
              {'amount': formatCents(availableCents, currency: currency)}),
          keepCase: true);
    }

    final account = status.value?.payoutAccount;
    Get.bottomSheet(
      ConfirmationSheet(
        title: LKey.confirmWithdrawal.tr,
        description: LKey.confirmWithdrawalDescription.trParams({
          'amount': formatCents(cents, currency: currency),
          'account':
              '${account?.payoutOperatorLabel ?? ''} ${account?.payoutPhoneMasked ?? ''}'
                  .trim(),
        }),
        positiveText: LKey.withdraw.tr,
        onTap: () => _send(cents),
      ),
      isScrollControlled: true,
    );
  }

  Future<void> _send(int cents) async {
    final response = await withLoader(() => GiftWalletService.instance
        .submitEarningsWithdrawal(amount: centsToAmountParam(cents)));
    if (response == null) return;
    if (response.wallet != null) _cacheWallet(response.wallet!);
    if (response.status == true) {
      // Close the screen before the snackbar: Get.back() would only dismiss
      // an open snackbar.
      Get.back(result: true);
      showSnackBar(response.message, keepCase: true, second: 4);
      return;
    }
    // A refusal over the checklist carries the fresh checklist.
    if (response.eligibility != null && status.value != null) {
      status.value!.eligibility = response.eligibility;
      status.refresh();
    }
    showSnackBar(response.message, keepCase: true, second: 4);
  }

  void _cacheWallet(EarningsWallet fresh) {
    final user = SessionManager.instance.getUser();
    if (user == null) return;
    fresh.applyTo(user);
    SessionManager.instance.setUser(user);
  }
}

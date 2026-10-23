import 'package:flutter/widgets.dart';
import 'package:get/get.dart';
import 'package:shortzz/common/controller/base_controller.dart';
import 'package:shortzz/common/manager/logger.dart';
import 'package:shortzz/common/manager/session_manager.dart';
import 'package:shortzz/common/service/api/gift_wallet_service.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/model/gift_wallet/creator_status_model.dart';

/// Registers or changes where a creator's withdrawals are sent. The server
/// checks the number against the name the network has on file (where it
/// can) and holds withdrawals for a while after any change.
class PayoutAccountScreenController extends BaseController {
  final TextEditingController legalNameController = TextEditingController();
  final TextEditingController phoneController = TextEditingController();
  final Rx<PayoutAccount?> account = Rx<PayoutAccount?>(null);
  final RxList<PayoutOperator> operators = PayoutOperator.defaults.toList().obs;
  final RxString selectedOperator = 'airtel'.obs;
  final RxBool isAdult = false.obs;

  int get holdHours =>
      SessionManager.instance.getSettings()?.payoutChangeHoldHours ?? 72;

  @override
  void onInit() {
    super.onInit();
    fetchAccount();
  }

  @override
  void onClose() {
    legalNameController.dispose();
    phoneController.dispose();
    super.onClose();
  }

  Future<void> fetchAccount() async {
    try {
      final response = await GiftWalletService.instance.fetchCreatorStatus();
      final data = response.data;
      if (response.status == true && data != null) {
        if (data.operators.isNotEmpty) operators.assignAll(data.operators);
        account.value = data.payoutAccount;
        final existing = data.payoutAccount;
        if (existing != null) {
          if (legalNameController.text.isEmpty) {
            legalNameController.text = existing.legalName ?? '';
          }
          selectedOperator.value = existing.payoutOperator ?? selectedOperator.value;
          isAdult.value = true; // confirmed when the account was first saved
        }
      }
    } catch (e) {
      Loggers.error('fetchCreatorStatus failed: $e');
    }
  }

  /// Picks the network from the number's prefix as it is typed — the same
  /// rule the server applies, so a mismatch is caught here first.
  void onPhoneChanged(String value) {
    final key = PayoutOperator.keyForPhone(value);
    if (key != null) selectedOperator.value = key;
  }

  Future<void> save() async {
    final String legalName = legalNameController.text.trim().replaceAll(RegExp(r'\s+'), ' ');
    final String phone = phoneController.text.trim();
    if (legalName.split(' ').where((p) => p.length >= 2).length < 2) {
      return showSnackBar(LKey.enterLegalName.tr, keepCase: true);
    }
    if (phone.isEmpty) {
      return showSnackBar(LKey.enterMobileMoneyNumber.tr, keepCase: true);
    }
    final String? prefixOperator = PayoutOperator.keyForPhone(phone);
    if (prefixOperator == null) {
      return showSnackBar(LKey.invalidMobileMoneyNumber.tr, keepCase: true);
    }
    if (prefixOperator != selectedOperator.value) {
      final label = operators.firstWhereOrNull((o) => o.key == selectedOperator.value)?.label ?? '';
      return showSnackBar(LKey.numberNotOnNetwork.trParams({'network': label}), keepCase: true);
    }
    if (!isAdult.value) {
      return showSnackBar(LKey.confirmAdultRequired.tr, keepCase: true);
    }

    final result = await withLoader(() async {
      try {
        return await GiftWalletService.instance.savePayoutAccount(
            legalName: legalName, phone: phone, operator: selectedOperator.value);
      } catch (e) {
        // HTTP 429: five saves a day per user (each save looks the number up
        // with the payout provider).
        if (e.toString().contains('RateLimited')) {
          return SavePayoutAccountModel(status: false, message: LKey.payoutAccountTooManyChanges.tr);
        }
        rethrow;
      }
    });
    if (result == null) return;
    if (result.payoutAccount != null) {
      account.value = result.payoutAccount;
      phoneController.clear();
    }
    showSnackBar(result.message, keepCase: true, second: 4);
  }
}

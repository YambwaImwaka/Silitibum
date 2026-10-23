import 'package:flutter/widgets.dart';
import 'package:get/get.dart';
import 'package:shortzz/common/controller/base_controller.dart';
import 'package:shortzz/common/manager/logger.dart';
import 'package:shortzz/common/manager/session_manager.dart';
import 'package:shortzz/common/service/api/gift_wallet_service.dart';
import 'package:shortzz/model/gift_wallet/creator_status_model.dart';
import 'package:shortzz/model/gift_wallet/wallet_transaction_model.dart';

/// The creator earnings screen (wallet v2): balances, the payout account and
/// the full money history, newest first.
class EarningsScreenController extends BaseController {
  final Rx<CreatorStatus?> status = Rx<CreatorStatus?>(null);
  final Rx<EarningsWallet> wallet =
      Rx(EarningsWallet.fromUser(SessionManager.instance.getUser()));
  final RxList<WalletTransaction> transactions = <WalletTransaction>[].obs;
  final RxBool isLoadingHistory = false.obs;
  final ScrollController scrollController = ScrollController();
  bool _hasMore = true;

  String get currency => status.value?.currency ?? SessionManager.instance.getCurrency();

  int get sharePercent =>
      status.value?.creatorSharePercent ??
      SessionManager.instance.getSettings()?.creatorSharePercent ??
      60;

  int get holdDays =>
      status.value?.earningsHoldDays ??
      SessionManager.instance.getSettings()?.earningsHoldDays ??
      7;

  @override
  void onInit() {
    super.onInit();
    scrollController.addListener(() {
      if (scrollController.position.pixels >=
          scrollController.position.maxScrollExtent - 200) {
        fetchHistory();
      }
    });
    refreshAll();
  }

  @override
  void onClose() {
    scrollController.dispose();
    super.onClose();
  }

  Future<void> refreshAll() async {
    _hasMore = true;
    transactions.clear();
    await Future.wait([fetchStatus(), fetchHistory()]);
  }

  Future<void> fetchStatus() async {
    try {
      final response = await GiftWalletService.instance.fetchCreatorStatus();
      final data = response.data;
      if (response.status == true && data != null) {
        status.value = data;
        if (data.wallet != null) {
          _applyWallet(data.wallet!);
        }
      }
    } catch (e) {
      Loggers.error('fetchCreatorStatus failed: $e');
    }
  }

  Future<void> fetchHistory() async {
    if (isLoadingHistory.value || !_hasMore) return;
    isLoadingHistory.value = true;
    try {
      final response = await GiftWalletService.instance.fetchWalletTransactions(
          lastItemId: transactions.isEmpty ? null : transactions.last.id);
      final rows = response.data?.transactions ?? [];
      transactions.addAll(rows);
      if (rows.isEmpty) _hasMore = false;
      if (response.data?.wallet != null) {
        _applyWallet(response.data!.wallet!);
      }
    } catch (e) {
      // A failed page must not leave the spinner running forever.
      Loggers.error('fetchWalletTransactions failed: $e');
    } finally {
      isLoadingHistory.value = false;
    }
  }

  /// Keeps the on-screen and cached balances in step with the server.
  void _applyWallet(EarningsWallet fresh) {
    wallet.value = fresh;
    final user = SessionManager.instance.getUser();
    if (user != null) {
      fresh.applyTo(user);
      SessionManager.instance.setUser(user);
    }
  }
}

import 'package:shortzz/common/service/api/api_service.dart';
import 'package:shortzz/common/service/utils/params.dart';
import 'package:shortzz/common/service/utils/web_service.dart';
import 'package:shortzz/model/general/status_model.dart';
import 'package:shortzz/model/gift_wallet/creator_status_model.dart';
import 'package:shortzz/model/gift_wallet/wallet_transaction_model.dart';
import 'package:shortzz/model/gift_wallet/withdraw_model.dart';
import 'package:shortzz/model/user_model/user_model.dart';
import 'package:shortzz/utilities/app_res.dart';

class GiftWalletService {
  GiftWalletService._();

  static final GiftWalletService instance = GiftWalletService._();

  /// [postId] is the reel/post the gift was sent on, so the creator's history
  /// can show what each video earned. The server ignores it unless the post
  /// belongs to the receiver; chat, story and live gifts send none.
  Future<StatusModel> sendGift({int? userId, int? giftId, int? postId}) async {
    StatusModel response = await ApiService.instance.call(
        url: WebService.giftWallet.sendGift,
        fromJson: StatusModel.fromJson,
        param: {
          Params.userId: userId,
          Params.giftId: giftId,
          Params.postId: postId,
        });
    return response;
  }

  Future<List<Withdraw>> fetchMyWithdrawalRequest({int? lastItemId}) async {
    WithdrawModel response = await ApiService.instance.call(
        url: WebService.giftWallet.fetchMyWithdrawalRequest,
        fromJson: WithdrawModel.fromJson,
        param: {
          Params.limit: AppRes.paginationLimit,
          Params.lastItemId: lastItemId,
        });

    return response.data ?? [];
  }

  /// Legacy (wallet v1) coin cash-out. A wallet v2 server answers it with
  /// "Please update Silitibum to withdraw your earnings."
  Future<StatusModel> submitWithdrawalRequest(
      {required String coins,
      required String gateway,
      required String account}) async {
    StatusModel response = await ApiService.instance.call(
        url: WebService.giftWallet.submitWithdrawalRequest,
        fromJson: StatusModel.fromJson,
        param: {
          Params.coins: coins,
          Params.gateway: gateway,
          Params.account: account
        });

    return response;
  }

  /// Wallet v2: asks for [amount] of the creator's earnings ("60" or
  /// "60.50", in the settings currency) to be paid out. No destination is
  /// sent — the server pays the registered payout account, so a stolen
  /// session cannot redirect a creator's money.
  Future<WithdrawalSubmitModel> submitEarningsWithdrawal(
      {required String amount}) async {
    WithdrawalSubmitModel response = await ApiService.instance.call(
        url: WebService.giftWallet.submitWithdrawalRequest,
        fromJson: WithdrawalSubmitModel.fromJson,
        param: {Params.amount: amount});
    return response;
  }

  /// Balances, creator share, payout account and the withdrawal checklist.
  Future<CreatorStatusModel> fetchCreatorStatus() async {
    CreatorStatusModel response = await ApiService.instance.call(
        url: WebService.giftWallet.fetchCreatorStatus,
        fromJson: CreatorStatusModel.fromJson);
    return response;
  }

  /// Registers or replaces where withdrawals are sent. Limited server-side
  /// to 5 saves a day per user: past that the API answers HTTP 429, which
  /// ApiService throws as an exception mentioning `RateLimited`.
  Future<SavePayoutAccountModel> savePayoutAccount(
      {required String legalName,
      required String phone,
      required String operator}) async {
    SavePayoutAccountModel response = await ApiService.instance.call(
        url: WebService.giftWallet.savePayoutAccount,
        fromJson: SavePayoutAccountModel.fromJson,
        param: {
          Params.legalName: legalName,
          Params.phone: phone,
          Params.operator: operator,
          // The creator ticked "I am 18 or older"; the form will not submit
          // without it.
          Params.confirmAdult: 1,
        });
    return response;
  }

  /// The signed-in user's wallet ledger, newest first. [account] narrows it
  /// to one balance; null returns both.
  Future<WalletTransactionsModel> fetchWalletTransactions(
      {int? lastItemId, WalletAccount? account}) async {
    WalletTransactionsModel response = await ApiService.instance.call(
        url: WebService.giftWallet.fetchWalletTransactions,
        fromJson: WalletTransactionsModel.fromJson,
        param: {
          Params.limit: AppRes.paginationLimit,
          Params.lastItemId: lastItemId,
          Params.account: account == null || account == WalletAccount.unknown
              ? null
              : account.value,
        });
    return response;
  }

  Future<User?> buyCoins({required int id, String? purchasedAt}) async {
    UserModel response = await ApiService.instance.call(
        url: WebService.giftWallet.buyCoins,
        fromJson: UserModel.fromJson,
        param: {
          Params.coinPackageId: id,
          Params.purchasedAt: purchasedAt,
        });
    if (response.status == true) {
      return response.data;
    }
    return null;
  }
}

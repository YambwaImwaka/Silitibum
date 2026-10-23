import 'package:shortzz/common/functions/json_parse.dart';
import 'package:shortzz/common/service/api/post_service.dart';
import 'package:shortzz/model/general/settings_model.dart';
import 'package:shortzz/model/gift_wallet/creator_status_model.dart';
import 'package:shortzz/model/user_model/user_model.dart';

Map<String, dynamic>? _asMap(dynamic value) =>
    value is Map ? Map<String, dynamic>.from(value) : null;

/// misc/fetchWalletTransactions.
class WalletTransactionsModel {
  WalletTransactionsModel({this.status, this.message, this.data});

  WalletTransactionsModel.fromJson(dynamic json) {
    status = asBoolOrNull(json['status']);
    message = asStringOrNull(json['message']);
    final data = _asMap(json['data']);
    this.data = data == null ? null : WalletTransactionsData.fromJson(data);
  }

  bool? status;
  String? message;
  WalletTransactionsData? data;
}

class WalletTransactionsData {
  WalletTransactionsData.fromJson(Map<String, dynamic> json) {
    final wallet = _asMap(json['wallet']);
    this.wallet = wallet == null ? null : EarningsWallet.fromJson(wallet);
    creatorSharePercent = asIntOrNull(json['creator_share_percent']);
    final rows = json['transactions'];
    transactions = rows is List
        ? rows
            .whereType<Map>()
            .map((e) => WalletTransaction.fromJson(Map<String, dynamic>.from(e)))
            .toList()
        : <WalletTransaction>[];
  }

  EarningsWallet? wallet;
  int? creatorSharePercent;
  List<WalletTransaction> transactions = [];
}

/// Which balance a ledger row moved.
enum WalletAccount {
  coins('coins'),
  earnings('earnings'),
  unknown('');

  const WalletAccount(this.value);

  /// The `account` filter value of fetchWalletTransactions.
  final String value;

  static WalletAccount fromValue(dynamic value) {
    final String? v = asStringOrNull(value)?.trim().toLowerCase();
    return WalletAccount.values.firstWhere(
        (e) => e != WalletAccount.unknown && e.value == v,
        orElse: () => WalletAccount.unknown);
  }
}

/// wallet_transactions.type. Unknown types degrade to [unknown] (a newer
/// server may add some) instead of throwing.
enum WalletTransactionType {
  purchaseTopup('purchase_topup'),
  purchaseIap('purchase_iap'),
  bonusRegistration('bonus_registration'),
  adminAdjustment('admin_adjustment'),
  giftSent('gift_sent'),
  giftReceived('gift_received'),
  withdrawal('withdrawal'),
  withdrawalRefund('withdrawal_refund'),
  iapRefund('iap_refund'),
  creatorFund('creator_fund'),
  migrationOpening('migration_opening'),

  /// The fan who sent this gift was refunded by the store, so the creator's
  /// share of it was taken back.
  giftReversed('gift_reversed'),
  unknown('');

  const WalletTransactionType(this.value);

  final String value;

  static WalletTransactionType fromValue(dynamic value) {
    final String? v = asStringOrNull(value)?.trim().toLowerCase();
    return WalletTransactionType.values.firstWhere(
        (e) => e != WalletTransactionType.unknown && e.value == v,
        orElse: () => WalletTransactionType.unknown);
  }
}

/// The reel/post a gift was sent on.
class WalletTransactionPost {
  WalletTransactionPost.fromJson(Map<String, dynamic> json) {
    id = asIntOrNull(json['id']);
    postType = PostType.fromString(json['post_type']);
    thumbnail = asStringOrNull(json['thumbnail']);
    description = asStringOrNull(json['description']);
  }

  int? id;
  PostType postType = PostType.none;
  String? thumbnail;
  String? description;
}

/// One wallet ledger row of the signed-in user.
class WalletTransaction {
  WalletTransaction.fromJson(Map<String, dynamic> json) {
    id = asIntOrNull(json['id']);
    account = WalletAccount.fromValue(json['account']);
    rawType = asStringOrNull(json['type']);
    type = WalletTransactionType.fromValue(rawType);
    coins = asIntOrNull(json['coins']) ?? 0;
    amount = asDoubleOrNull(json['amount']) ?? 0;
    balanceAfterCoins = asIntOrNull(json['balance_after_coins']);
    balanceAfterAmount = asDoubleOrNull(json['balance_after_amount']);
    final String? created = asStringOrNull(json['created_at']);
    createdAt = created == null ? null : DateTime.tryParse(created);
    final counterparty = _asMap(json['counterparty']);
    this.counterparty =
        counterparty == null ? null : User.fromJson(counterparty);
    final gift = _asMap(json['gift']);
    this.gift = gift == null ? null : Gift.fromJson(gift);
    final post = _asMap(json['post']);
    this.post = post == null ? null : WalletTransactionPost.fromJson(post);
    livestreamId = asIntOrNull(json['livestream_id']);
    redeemRequestId = asIntOrNull(json['redeem_request_id']);
    note = asStringOrNull(json['note']);
  }

  int? id;
  WalletAccount account = WalletAccount.unknown;
  WalletTransactionType type = WalletTransactionType.unknown;
  String? rawType;

  /// Signed coin movement. On a gift row this is the gift's size, also on
  /// the receiver's earnings row.
  int coins = 0;

  /// Signed kwacha: the earnings moved, or on a coin row the value of the
  /// coins moved.
  double amount = 0;
  int? balanceAfterCoins;
  double? balanceAfterAmount;
  DateTime? createdAt;
  User? counterparty;
  Gift? gift;
  WalletTransactionPost? post;
  int? livestreamId;
  int? redeemRequestId;

  /// An admin adjustment's reason.
  String? note;

  bool get isCoinRow => account == WalletAccount.coins;

  /// Money (or coins) came in.
  bool get isCredit => isCoinRow ? coins > 0 : amount > 0;
}

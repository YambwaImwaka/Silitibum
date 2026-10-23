import 'package:shortzz/common/functions/json_parse.dart';
import 'package:shortzz/model/gift_wallet/withdraw_model.dart';
import 'package:shortzz/model/user_model/user_model.dart';

// Creator earnings wallet (settings wallet_v2). Every scalar goes through the
// json_parse coercers: production serves numbers as JSON strings, and money
// arrives as decimal strings ("12.50"). Nested objects are only parsed when
// they really are objects — PHP serialises an empty array as [], not {}.

Map<String, dynamic>? _asMap(dynamic value) =>
    value is Map ? Map<String, dynamic>.from(value) : null;

List<Map<String, dynamic>> _asMapList(dynamic value) => value is List
    ? value.whereType<Map>().map((e) => Map<String, dynamic>.from(e)).toList()
    : <Map<String, dynamic>>[];

/// misc/fetchCreatorStatus.
class CreatorStatusModel {
  CreatorStatusModel({this.status, this.message, this.data});

  CreatorStatusModel.fromJson(dynamic json) {
    status = asBoolOrNull(json['status']);
    message = asStringOrNull(json['message']);
    final data = _asMap(json['data']);
    this.data = data == null ? null : CreatorStatus.fromJson(data);
  }

  bool? status;
  String? message;
  CreatorStatus? data;
}

/// Balances, the creator share, the payout account and the withdrawal
/// checklist — everything the earnings screens need in one payload.
class CreatorStatus {
  CreatorStatus({
    this.wallet,
    this.creatorSharePercent,
    this.currency,
    this.minWithdrawalAmount,
    this.earningsHoldDays,
    this.maxWithdrawalsPerWeek,
    this.withdrawalsEnabled = false,
    this.operators = const [],
    this.payoutAccount,
    this.eligibility,
    this.openWithdrawal,
  });

  CreatorStatus.fromJson(Map<String, dynamic> json) {
    final wallet = _asMap(json['wallet']);
    this.wallet = wallet == null ? null : EarningsWallet.fromJson(wallet);
    creatorSharePercent = asIntOrNull(json['creator_share_percent']);
    currency = asStringOrNull(json['currency']);
    minWithdrawalAmount = asDoubleOrNull(json['min_withdrawal_amount']);
    earningsHoldDays = asIntOrNull(json['earnings_hold_days']);
    maxWithdrawalsPerWeek = asIntOrNull(json['max_withdrawals_per_week']);
    withdrawalsEnabled = asBoolOrNull(json['withdrawals_enabled']) ?? false;
    operators = _asMapList(json['operators'])
        .map(PayoutOperator.fromJson)
        .where((o) => o.key.isNotEmpty)
        .toList();
    final account = _asMap(json['payout_account']);
    payoutAccount = account == null ? null : PayoutAccount.fromJson(account);
    final eligibility = _asMap(json['eligibility']);
    this.eligibility =
        eligibility == null ? null : CreatorEligibility.fromJson(eligibility);
    final open = _asMap(json['open_withdrawal']);
    openWithdrawal = open == null ? null : Withdraw.fromJson(open);
  }

  EarningsWallet? wallet;
  int? creatorSharePercent;
  String? currency;
  double? minWithdrawalAmount;
  int? earningsHoldDays;
  int? maxWithdrawalsPerWeek;
  bool withdrawalsEnabled = false;
  List<PayoutOperator> operators = const [];
  PayoutAccount? payoutAccount;
  CreatorEligibility? eligibility;

  /// A pending or processing withdrawal; only one may be open at a time.
  Withdraw? openWithdrawal;
}

/// The creator's K balances. The self user payload carries the same four
/// numbers, so the screens can show cached values before the status loads.
class EarningsWallet {
  EarningsWallet({
    this.earningsBalance,
    this.earningsAvailable,
    this.earningsOnHold,
    this.earningsLifetime,
  });

  EarningsWallet.fromJson(Map<String, dynamic> json) {
    earningsBalance = asDoubleOrNull(json['earnings_balance']);
    earningsAvailable = asDoubleOrNull(json['earnings_available']);
    earningsOnHold = asDoubleOrNull(json['earnings_on_hold']);
    earningsLifetime = asDoubleOrNull(json['earnings_lifetime']);
  }

  factory EarningsWallet.fromUser(User? user) => EarningsWallet(
        earningsBalance: user?.earningsBalance,
        earningsAvailable: user?.earningsAvailable,
        earningsOnHold: user?.earningsOnHold,
        earningsLifetime: user?.earningsLifetime,
      );

  double? earningsBalance;
  double? earningsAvailable;
  double? earningsOnHold;
  double? earningsLifetime;

  /// Copies these balances onto the cached self user.
  void applyTo(User user) {
    user.earningsBalance = earningsBalance ?? user.earningsBalance;
    user.earningsAvailable = earningsAvailable ?? user.earningsAvailable;
    user.earningsOnHold = earningsOnHold ?? user.earningsOnHold;
    user.earningsLifetime = earningsLifetime ?? user.earningsLifetime;
  }
}

/// A mobile-money network payouts can be sent to.
class PayoutOperator {
  const PayoutOperator({required this.key, required this.label});

  factory PayoutOperator.fromJson(Map<String, dynamic> json) => PayoutOperator(
        key: asStringOrNull(json['key']) ?? '',
        label: asStringOrNull(json['label']) ?? '',
      );

  /// airtel | mtn | zamtel — what savePayoutAccount expects.
  final String key;
  final String label;

  /// The networks the backend accepts (MobileMoney::OPERATORS), for when a
  /// status payload carries none.
  static const List<PayoutOperator> defaults = [
    PayoutOperator(key: 'airtel', label: 'Airtel Money'),
    PayoutOperator(key: 'mtn', label: 'MTN MoMo'),
    PayoutOperator(key: 'zamtel', label: 'Zamtel Kwacha'),
  ];

  /// The network a Zambian number belongs to, by its national prefix
  /// (MobileMoney::operatorFor): MTN 096/076, Airtel 097/077, Zamtel
  /// 095/075. Accepts "0971234567", "971234567", "+260971234567" and
  /// "00260971234567".
  static String? keyForPhone(String phone) {
    String digits = phone.replaceAll(RegExp(r'[^0-9]'), '');
    if (digits.startsWith('00')) digits = digits.substring(2);
    if (digits.startsWith('260')) digits = digits.substring(3);
    if (!digits.startsWith('0')) digits = '0$digits';
    if (digits.length < 3) return null;
    return switch (digits.substring(0, 3)) {
      '096' || '076' => 'mtn',
      '097' || '077' => 'airtel',
      '095' || '075' => 'zamtel',
      _ => null,
    };
  }
}

/// Whether the name the mobile-money network has on file matches the
/// creator's legal name. Never throws: anything unknown is [unverified].
enum NameMatch {
  match,
  mismatch,
  unverified;

  static NameMatch fromValue(dynamic value) =>
      switch (asStringOrNull(value)?.trim().toLowerCase()) {
        'match' => NameMatch.match,
        'mismatch' => NameMatch.mismatch,
        _ => NameMatch.unverified,
      };
}

/// The registered payout account, as the API may show it: the phone number
/// is masked and the network's registered name is never included.
class PayoutAccount {
  PayoutAccount({
    this.legalName,
    this.payoutPhoneMasked,
    this.payoutOperator,
    this.payoutOperatorLabel,
    this.nameMatch = NameMatch.unverified,
    this.nameOverride = false,
    this.status,
  });

  PayoutAccount.fromJson(Map<String, dynamic> json) {
    legalName = asStringOrNull(json['legal_name']);
    payoutPhoneMasked = asStringOrNull(json['payout_phone_masked']);
    payoutOperator = asStringOrNull(json['payout_operator']);
    payoutOperatorLabel = asStringOrNull(json['payout_operator_label']);
    nameMatch = NameMatch.fromValue(json['name_match']);
    nameOverride = asBoolOrNull(json['name_override']) ?? false;
    status = asStringOrNull(json['status']);
  }

  String? legalName;

  /// "••••••4567".
  String? payoutPhoneMasked;
  String? payoutOperator;
  String? payoutOperatorLabel;
  NameMatch nameMatch = NameMatch.unverified;

  /// Support approved the account despite a name mismatch.
  bool nameOverride = false;

  /// active | suspended.
  String? status;

  bool get isSuspended => status == 'suspended';
}

/// The withdrawal checklist (CreatorProgram::eligibility).
class CreatorEligibility {
  CreatorEligibility({this.eligible = false, this.checks = const []});

  CreatorEligibility.fromJson(Map<String, dynamic> json) {
    eligible = asBoolOrNull(json['eligible']) ?? false;
    checks = _asMapList(json['checks']).map(EligibilityCheck.fromJson).toList();
  }

  bool eligible = false;
  List<EligibilityCheck> checks = const [];
}

class EligibilityCheck {
  EligibilityCheck({this.key, this.ok = false, this.label, this.availableAt});

  EligibilityCheck.fromJson(Map<String, dynamic> json) {
    key = asStringOrNull(json['key']);
    ok = asBoolOrNull(json['ok']) ?? false;
    label = asStringOrNull(json['label']);
    final String? at = asStringOrNull(json['available_at']);
    availableAt = at == null ? null : DateTime.tryParse(at);
  }

  /// account_age | good_standing | payout_account | name_match |
  /// payout_change_hold.
  String? key;
  bool ok = false;

  /// Already human readable ("Account at least 30 days old").
  String? label;

  /// When an unmet time-based check clears by itself.
  DateTime? availableAt;
}

/// misc/savePayoutAccount.
class SavePayoutAccountModel {
  SavePayoutAccountModel({this.status, this.message});

  SavePayoutAccountModel.fromJson(dynamic json) {
    status = asBoolOrNull(json['status']);
    message = asStringOrNull(json['message']);
    final data = _asMap(json['data']);
    final account = _asMap(data?['payout_account']);
    payoutAccount = account == null ? null : PayoutAccount.fromJson(account);
    final eligibility = _asMap(data?['eligibility']);
    this.eligibility =
        eligibility == null ? null : CreatorEligibility.fromJson(eligibility);
  }

  bool? status;
  String? message;
  PayoutAccount? payoutAccount;
  CreatorEligibility? eligibility;
}

/// misc/submitWithdrawalRequest with an `amount` (wallet v2).
class WithdrawalSubmitModel {
  WithdrawalSubmitModel({this.status, this.message});

  WithdrawalSubmitModel.fromJson(dynamic json) {
    status = asBoolOrNull(json['status']);
    message = asStringOrNull(json['message']);
    final data = _asMap(json['data']);
    final withdrawal = _asMap(data?['withdrawal']);
    this.withdrawal = withdrawal == null ? null : Withdraw.fromJson(withdrawal);
    final wallet = _asMap(data?['wallet']);
    this.wallet = wallet == null ? null : EarningsWallet.fromJson(wallet);
    final eligibility = _asMap(data?['eligibility']);
    this.eligibility =
        eligibility == null ? null : CreatorEligibility.fromJson(eligibility);
  }

  bool? status;
  String? message;
  Withdraw? withdrawal;
  EarningsWallet? wallet;

  /// Sent with a refusal caused by an unmet checklist item.
  CreatorEligibility? eligibility;
}

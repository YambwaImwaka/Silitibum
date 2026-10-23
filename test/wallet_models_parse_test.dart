// The creator earnings wallet (settings wallet_v2) parses money the way
// production serves it: every number as a JSON string, money as decimal
// strings ("60.00", "4.0000"), and empty objects as PHP's [] instead of {}.
import 'package:flutter_test/flutter_test.dart';
import 'package:shortzz/common/functions/money_format.dart';
import 'package:shortzz/model/general/settings_model.dart';
import 'package:shortzz/model/gift_wallet/creator_status_model.dart';
import 'package:shortzz/model/gift_wallet/wallet_transaction_model.dart';
import 'package:shortzz/model/user_model/user_model.dart';

void main() {
  group('money_format', () {
    test('parseCents reads decimal strings and numbers', () {
      expect(parseCents('60'), 6000);
      expect(parseCents('60.5'), 6050);
      expect(parseCents('60.50'), 6050);
      expect(parseCents('4.0050'), 401); // half up past the cent
      expect(parseCents('4.0049'), 400);
      expect(parseCents('.75'), 75);
      expect(parseCents('-1.25'), -125);
      expect(parseCents(12.5), 1250);
      expect(parseCents(0.1 + 0.2), 30);
    });

    test('parseCents refuses anything that is not a plain decimal', () {
      expect(parseCents(null), isNull);
      expect(parseCents(''), isNull);
      expect(parseCents('.'), isNull);
      expect(parseCents('abc'), isNull);
      expect(parseCents('1,000'), isNull);
      expect(parseCents('1.2.3'), isNull);
      expect(parseCents('1234567890123'), isNull);
      expect(parseCents(double.nan), isNull);
    });

    test('centsToAmountParam always sends two decimals', () {
      expect(centsToAmountParam(6000), '60.00');
      expect(centsToAmountParam(6050), '60.50');
      expect(centsToAmountParam(5), '0.05');
      expect(centsToAmountParam(0), '0.00');
    });

    test('formatMoney', () {
      expect(formatMoney(1234.5, currency: 'K'), 'K1,234.50');
      expect(formatMoney(-60, currency: 'K'), '-K60.00');
      expect(formatMoney(0.6, currency: 'K', signed: true), '+K0.60');
      expect(formatMoney(null, currency: 'K'), 'K0.00');
      expect(formatCents(401, currency: 'K'), 'K4.01');
    });
  });

  group('Setting wallet v2 fields', () {
    test('parse from strings', () {
      final model = SettingModel.fromJson({
        'status': true,
        'message': 'ok',
        'data': {
          'wallet_v2': '1',
          'creator_share_percent': '60',
          'min_withdrawal_amount': '50.00',
          'earnings_hold_days': '7',
          'max_withdrawals_per_week': '1',
          'currency_code': 'ZMW',
          'creator_min_account_age_days': '30',
          'payout_change_hold_hours': '72',
        },
      });
      final data = model.data!;
      expect(data.isWalletV2, isTrue);
      expect(data.creatorSharePercent, 60);
      expect(data.minWithdrawalAmount, 50.0);
      expect(data.earningsHoldDays, 7);
      expect(data.maxWithdrawalsPerWeek, 1);
      expect(data.currencyCode, 'ZMW');
      expect(data.creatorMinAccountAgeDays, 30);
      expect(data.payoutChangeHoldHours, 72);
    });

    test('a server without the flag keeps the old wallet', () {
      final model = SettingModel.fromJson({
        'status': true,
        'message': 'ok',
        'data': <String, dynamic>{},
      });
      expect(model.data!.isWalletV2, isFalse);
      expect(
          SettingModel.fromJson({
            'status': true,
            'data': {'wallet_v2': '0'}
          }).data!.isWalletV2,
          isFalse);
    });
  });

  test('User earnings fields parse from strings', () {
    final user = User.fromJson({
      'id': '7',
      'earnings_balance': '12.5000',
      'earnings_available': '10.0000',
      'earnings_on_hold': '2.5000',
      'earnings_lifetime': '40.0000',
    });
    expect(user.earningsBalance, 12.5);
    expect(user.earningsAvailable, 10.0);
    expect(user.earningsOnHold, 2.5);
    expect(user.earningsLifetime, 40.0);

    final wallet = EarningsWallet.fromUser(user);
    expect(wallet.earningsAvailable, 10.0);
  });

  group('CreatorStatusModel', () {
    test('parses the production shape', () {
      final model = CreatorStatusModel.fromJson({
        'status': true,
        'message': 'creator status fetched successfully',
        'data': {
          'wallet': {
            'earnings_balance': '62.0000',
            'earnings_available': '60.0000',
            'earnings_on_hold': '2.0000',
            'earnings_lifetime': '80.0000',
          },
          'creator_share_percent': '60',
          'currency': 'K',
          'min_withdrawal_amount': '50.0000',
          'earnings_hold_days': '7',
          'max_withdrawals_per_week': '1',
          'withdrawals_enabled': false,
          'operators': [
            {'key': 'airtel', 'label': 'Airtel Money'},
            {'key': 'mtn', 'label': 'MTN MoMo'},
            {'key': '', 'label': 'Broken'},
          ],
          'payout_account': {
            'legal_name': 'Mwila Banda',
            'payout_phone_masked': '••••••4567',
            'payout_operator': 'airtel',
            'payout_operator_label': 'Airtel Money',
            'name_match': 'match',
            'name_override': '0',
            'status': 'active',
          },
          'eligibility': {
            'eligible': false,
            'checks': [
              {'key': 'account_age', 'ok': true, 'label': 'Account at least 30 days old'},
              {
                'key': 'payout_change_hold',
                'ok': false,
                'label': 'Payout number unchanged for 72 hours',
                'available_at': '2026-10-25T10:00:00.000000Z',
              },
            ],
          },
          'open_withdrawal': {
            'id': '9',
            'request_number': 'W7-ABC',
            'amount': '50.00',
            'status': '0',
            'created_at': '2026-10-20T10:00:00.000000Z',
          },
        },
      });

      final data = model.data!;
      expect(model.status, isTrue);
      expect(data.wallet!.earningsAvailable, 60.0);
      expect(parseCents(data.wallet!.earningsAvailable), 6000);
      expect(data.creatorSharePercent, 60);
      expect(parseCents(data.minWithdrawalAmount), 5000);
      expect(data.withdrawalsEnabled, isFalse);
      expect(data.operators.map((o) => o.key), ['airtel', 'mtn']);
      expect(data.payoutAccount!.nameMatch, NameMatch.match);
      expect(data.payoutAccount!.nameOverride, isFalse);
      expect(data.payoutAccount!.isSuspended, isFalse);
      expect(data.eligibility!.eligible, isFalse);
      expect(data.eligibility!.checks, hasLength(2));
      expect(data.eligibility!.checks.last.availableAt, isNotNull);
      expect(data.openWithdrawal!.status, 0);
      expect(data.openWithdrawal!.amount, '50.00');
    });

    test('PHP empty arrays read as missing objects', () {
      final model = CreatorStatusModel.fromJson({
        'status': 'true',
        'data': {
          'wallet': [],
          'operators': [],
          'payout_account': null,
          'eligibility': [],
          'open_withdrawal': null,
          'withdrawals_enabled': '1',
        },
      });
      final data = model.data!;
      expect(data.wallet, isNull);
      expect(data.operators, isEmpty);
      expect(data.payoutAccount, isNull);
      expect(data.eligibility, isNull);
      expect(data.openWithdrawal, isNull);
      expect(data.withdrawalsEnabled, isTrue);
    });

    test('unknown name_match values are unverified, never a throw', () {
      expect(NameMatch.fromValue('MATCH'), NameMatch.match);
      expect(NameMatch.fromValue('mismatch'), NameMatch.mismatch);
      expect(NameMatch.fromValue(null), NameMatch.unverified);
      expect(NameMatch.fromValue('lookup_failed'), NameMatch.unverified);
    });
  });

  test('PayoutOperator.keyForPhone follows Zambian prefixes', () {
    expect(PayoutOperator.keyForPhone('0971234567'), 'airtel');
    expect(PayoutOperator.keyForPhone('0771234567'), 'airtel');
    expect(PayoutOperator.keyForPhone('0961234567'), 'mtn');
    expect(PayoutOperator.keyForPhone('+260 96 123 4567'), 'mtn');
    expect(PayoutOperator.keyForPhone('00260951234567'), 'zamtel');
    expect(PayoutOperator.keyForPhone('751234567'), 'zamtel');
    expect(PayoutOperator.keyForPhone('0211234567'), isNull);
    expect(PayoutOperator.keyForPhone(''), isNull);
  });

  test('WalletTransactionsModel parses ledger rows', () {
    final model = WalletTransactionsModel.fromJson({
      'status': true,
      'data': {
        'wallet': {'earnings_available': '0.6000'},
        'creator_share_percent': '60',
        'transactions': [
          {
            'id': '31',
            'account': 'earnings',
            'type': 'gift_received',
            'coins': '100',
            'amount': '0.6000',
            'balance_after_coins': null,
            'balance_after_amount': '0.6000',
            'created_at': '2026-10-20T10:00:00.000000Z',
            'counterparty': {'id': '5', 'username': 'fan'},
            'gift': [],
            'post': {
              'id': '12',
              'post_type': '2',
              'thumbnail': 'a.jpg',
              'description': 'My reel',
            },
            'livestream_id': null,
            'redeem_request_id': null,
            'note': null,
          },
          {
            'id': '30',
            'account': 'coins',
            'type': 'gift_sent',
            'coins': '-100',
            'amount': '-1.0000',
          },
          {'id': '29', 'account': 'earnings', 'type': 'something_new', 'amount': '1'},
        ],
      },
    });

    final rows = model.data!.transactions;
    expect(model.data!.creatorSharePercent, 60);
    expect(rows, hasLength(3));

    expect(rows[0].type, WalletTransactionType.giftReceived);
    expect(rows[0].isCoinRow, isFalse);
    expect(rows[0].isCredit, isTrue);
    expect(rows[0].amount, 0.6);
    expect(rows[0].counterparty!.username, 'fan');
    expect(rows[0].gift, isNull);
    expect(rows[0].post!.description, 'My reel');

    expect(rows[1].isCoinRow, isTrue);
    expect(rows[1].isCredit, isFalse);
    expect(rows[1].coins, -100);

    expect(rows[2].type, WalletTransactionType.unknown);
    expect(rows[2].rawType, 'something_new');
  });
}

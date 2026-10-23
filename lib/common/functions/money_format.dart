/// Kwacha amounts for the creator earnings wallet.
///
/// The backend carries money as decimal strings ("60.00", "4.0000") and
/// validates a withdrawal amount as `^\d{1,9}(\.\d{1,2})?$`. Wherever the app
/// compares or sends an amount it works in whole cents (ngwee, 1/100 K), so a
/// float like 0.1 + 0.2 never decides whether a creator may withdraw.
library;

import 'package:intl/intl.dart';

// Explicit locale: money must always read "1,234.50", whatever language the
// app is switched to.
final NumberFormat _money = NumberFormat('#,##0.00', 'en_US');

final RegExp _decimal = RegExp(r'^(-?)(\d*)(?:\.(\d*))?$');

/// "K1,234.50". Negative amounts read "-K60.00"; [signed] also marks
/// positive ones ("+K0.60"), for ledger rows.
String formatMoney(num? amount, {required String currency, bool signed = false}) {
  final num value = amount ?? 0;
  final String sign = value < 0 ? '-' : (signed && value > 0 ? '+' : '');
  return '$sign$currency${_money.format(value.abs())}';
}

/// [cents] as "K60.50".
String formatCents(int cents, {required String currency}) =>
    formatMoney(cents / 100, currency: currency);

/// Cents in an amount: "60" -> 6000, "60.5" -> 6050, "4.0050" -> 401 (half
/// up), 12.5 -> 1250. Null for anything that is not a plain decimal.
int? parseCents(dynamic value) {
  if (value == null) return null;
  if (value is num) {
    return value.isFinite ? (value * 100).round() : null;
  }
  final match = _decimal.firstMatch(value.toString().trim());
  if (match == null) return null;
  final String whole = match.group(2) ?? '';
  final String fraction = match.group(3) ?? '';
  if (whole.isEmpty && fraction.isEmpty) return null;
  // Twelve digits of kwacha is far beyond any balance; refusing longer input
  // keeps the int arithmetic below from overflowing.
  if (whole.length > 12) return null;

  final String padded = fraction.padRight(3, '0');
  int cents = int.parse(whole.isEmpty ? '0' : whole) * 100 +
      int.parse(padded.substring(0, 2));
  if (int.parse(padded[2]) >= 5) cents += 1;
  return match.group(1) == '-' ? -cents : cents;
}

/// The `amount` param for misc/submitWithdrawalRequest: always two decimals
/// ("60.00", "60.50"), which the server's amount rule accepts.
String centsToAmountParam(int cents) =>
    '${cents ~/ 100}.${(cents % 100).toString().padLeft(2, '0')}';

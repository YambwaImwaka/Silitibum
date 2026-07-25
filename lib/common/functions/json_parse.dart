/// Tolerant JSON scalar coercion.
///
/// The Laravel backend serves numeric MySQL columns as JSON *strings* in some
/// environments (PDO stringification on the production host), so `"is_verify":
/// "0"` and `"is_verify": 0` must both parse. Raw dynamic assignment into a
/// typed field (`int? x = json['k'];`) throws a TypeError on the string form —
/// which crashed login/signup parsing on an otherwise successful response.
library;

int? asIntOrNull(dynamic v) {
  if (v == null) return null;
  if (v is int) return v;
  if (v is num) return v.toInt();
  return int.tryParse(v.toString());
}

num? asNumOrNull(dynamic v) {
  if (v == null) return null;
  if (v is num) return v;
  return num.tryParse(v.toString());
}

double? asDoubleOrNull(dynamic v) => asNumOrNull(v)?.toDouble();

bool? asBoolOrNull(dynamic v) {
  if (v == null) return null;
  if (v is bool) return v;
  final s = v.toString().toLowerCase();
  if (s == '1' || s == 'true') return true;
  if (s == '0' || s == 'false') return false;
  return null;
}

String? asStringOrNull(dynamic v) => v?.toString();

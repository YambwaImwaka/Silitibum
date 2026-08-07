import 'package:shortzz/common/functions/json_parse.dart';

class ForgotPasswordModel {
  ForgotPasswordModel.fromJson(dynamic json) {
    status = asBoolOrNull(json['status']);
    message = asStringOrNull(json['message']);
    maskedEmail = asStringOrNull(json['data']?['masked_email']);
    helpMail = asStringOrNull(json['data']?['help_mail']);
  }

  bool? status;
  String? message;

  /// Support address, returned only with `no_recovery_email` — a phone-only
  /// account with no recovery address cannot self-serve a reset (no SMS
  /// gateway), so the message has to say who to contact.
  String? helpMail;

  /// Where the reset code was sent (e.g. y*******a@gmail.com) — shown to the
  /// user so phone-identity accounts know which recovery inbox to check.
  String? maskedEmail;
}

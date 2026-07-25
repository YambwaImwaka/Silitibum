import 'package:shortzz/common/functions/json_parse.dart';

class ForgotPasswordModel {
  ForgotPasswordModel.fromJson(dynamic json) {
    status = asBoolOrNull(json['status']);
    message = asStringOrNull(json['message']);
    maskedEmail = asStringOrNull(json['data']?['masked_email']);
  }

  bool? status;
  String? message;

  /// Where the reset code was sent (e.g. y*******a@gmail.com) — shown to the
  /// user so phone-identity accounts know which recovery inbox to check.
  String? maskedEmail;
}

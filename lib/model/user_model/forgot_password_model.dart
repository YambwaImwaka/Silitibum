class ForgotPasswordModel {
  ForgotPasswordModel.fromJson(dynamic json) {
    status = json['status'];
    message = json['message'];
    maskedEmail = json['data']?['masked_email'];
  }

  bool? status;
  String? message;

  /// Where the reset code was sent (e.g. y*******a@gmail.com) — shown to the
  /// user so phone-identity accounts know which recovery inbox to check.
  String? maskedEmail;
}

import 'dart:async';

import 'package:firebase_auth/firebase_auth.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:shortzz/common/controller/base_controller.dart';
import 'package:shortzz/common/manager/logger.dart';
import 'package:shortzz/common/widget/custom_back_button.dart';
import 'package:shortzz/common/widget/text_button_custom.dart';
import 'package:shortzz/common/widget/text_field_custom.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/utilities/text_style_custom.dart';
import 'package:shortzz/utilities/theme_res.dart';

/// Verifies phone possession with a Firebase SMS code, then hands the signed
/// in [UserCredential] to [onVerified] (which links credentials / registers /
/// navigates). Used by phone sign-up and phone password reset.
class OtpVerificationScreen extends StatelessWidget {
  final String phoneNumber;
  final Future<void> Function(UserCredential userCredential) onVerified;

  const OtpVerificationScreen(
      {super.key, required this.phoneNumber, required this.onVerified});

  @override
  Widget build(BuildContext context) {
    final controller = Get.put(
        OtpVerificationController(phoneNumber: phoneNumber, onVerified: onVerified));
    return Scaffold(
      body: SafeArea(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const SizedBox(height: 10),
            const CustomBackButton(
                padding: EdgeInsets.symmetric(horizontal: 20, vertical: 5)),
            Padding(
              padding: const EdgeInsets.only(left: 20, right: 20, top: 40),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(LKey.enterOtpCode.tr.toUpperCase(),
                      style: TextStyleCustom.unboundedBlack900(
                        fontSize: 22,
                        color: textDarkGrey(context),
                      ).copyWith(letterSpacing: -.2)),
                  const SizedBox(height: 10),
                  Text('${LKey.otpSentTo.tr} $phoneNumber',
                      style: TextStyleCustom.outFitRegular400(
                          fontSize: 16, color: textLightGrey(context))),
                ],
              ),
            ),
            const SizedBox(height: 30),
            TextFieldCustom(
              controller: controller.codeController,
              title: LKey.enterOtpCode.tr,
              keyboardType: TextInputType.number,
            ),
            const SizedBox(height: 10),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 20.0),
              child: Obx(() {
                final int seconds = controller.secondsLeft.value;
                return InkWell(
                  onTap: seconds == 0 ? controller.resendCode : null,
                  child: Padding(
                    padding: const EdgeInsets.symmetric(vertical: 8.0),
                    child: Text(
                      seconds == 0
                          ? LKey.resendCode.tr
                          : '${LKey.resendCode.tr} (${seconds}s)',
                      style: TextStyleCustom.outFitMedium500(
                          fontSize: 15,
                          color: seconds == 0
                              ? textDarkGrey(context)
                              : textLightGrey(context)),
                    ),
                  ),
                );
              }),
            ),
            const Spacer(),
            TextButtonCustom(
                onTap: controller.verifyCode,
                title: LKey.verify.tr,
                backgroundColor: textDarkGrey(context),
                horizontalMargin: 20,
                titleColor: whitePure(context)),
            SizedBox(height: AppBar().preferredSize.height / 1.2),
          ],
        ),
      ),
    );
  }
}

class OtpVerificationController extends BaseController {
  final String phoneNumber;
  final Future<void> Function(UserCredential userCredential) onVerified;

  OtpVerificationController(
      {required this.phoneNumber, required this.onVerified});

  final TextEditingController codeController = TextEditingController();
  final RxInt secondsLeft = 0.obs;

  String? _verificationId;
  int? _resendToken;
  Timer? _countdown;
  bool _completed = false;

  @override
  void onInit() {
    super.onInit();
    _sendCode();
  }

  @override
  void onClose() {
    _countdown?.cancel();
    codeController.dispose();
    super.onClose();
  }

  void resendCode() => _sendCode(isResend: true);

  Future<void> _sendCode({bool isResend = false}) async {
    _startCountdown();
    try {
      await FirebaseAuth.instance.verifyPhoneNumber(
        phoneNumber: phoneNumber,
        forceResendingToken: isResend ? _resendToken : null,
        timeout: const Duration(seconds: 60),
        verificationCompleted: (PhoneAuthCredential credential) async {
          // Android auto-retrieval: no typing needed.
          await _signInWith(credential);
        },
        verificationFailed: (FirebaseAuthException e) {
          Loggers.error('Phone verification failed: ${e.code} ${e.message}');
          secondsLeft.value = 0;
          showSnackBar(e.message);
        },
        codeSent: (String verificationId, int? resendToken) {
          _verificationId = verificationId;
          _resendToken = resendToken;
        },
        codeAutoRetrievalTimeout: (String verificationId) {
          _verificationId = verificationId;
        },
      );
    } catch (e) {
      Loggers.error('verifyPhoneNumber failed: $e');
      secondsLeft.value = 0;
      showSnackBar('Failed to send the code. Please try again.');
    }
  }

  Future<void> verifyCode() async {
    final String code = codeController.text.trim();
    if (code.isEmpty || _verificationId == null) {
      return showSnackBar(LKey.invalidOtp.tr);
    }
    final credential = PhoneAuthProvider.credential(
        verificationId: _verificationId!, smsCode: code);
    await _signInWith(credential);
  }

  Future<void> _signInWith(PhoneAuthCredential credential) async {
    if (_completed) return;
    showLoader();
    try {
      final userCred =
          await FirebaseAuth.instance.signInWithCredential(credential);
      stopLoader();
      if (userCred.user == null) {
        return showSnackBar(LKey.invalidOtp.tr);
      }
      _completed = true;
      // onVerified links credentials, registers with the backend and
      // navigates (Get.offAll disposes this screen).
      await onVerified(userCred);
    } on FirebaseAuthException catch (e) {
      stopLoader();
      Loggers.error('OTP sign-in failed: ${e.code} ${e.message}');
      showSnackBar(e.code == 'invalid-verification-code'
          ? LKey.invalidOtp.tr
          : e.message);
    } catch (e) {
      stopLoader();
      Loggers.error('OTP sign-in failed: $e');
      showSnackBar(LKey.invalidOtp.tr);
    }
  }

  void _startCountdown() {
    _countdown?.cancel();
    secondsLeft.value = 60;
    _countdown = Timer.periodic(const Duration(seconds: 1), (timer) {
      if (secondsLeft.value <= 1) {
        secondsLeft.value = 0;
        timer.cancel();
      } else {
        secondsLeft.value -= 1;
      }
    });
  }
}

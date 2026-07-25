import 'package:figma_squircle_updated/figma_squircle.dart';
import 'package:firebase_crashlytics/firebase_crashlytics.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:shortzz/common/controller/base_controller.dart';
import 'package:shortzz/common/manager/logger.dart';
import 'package:shortzz/common/widget/bottom_sheet_top_view.dart';
import 'package:shortzz/common/widget/text_button_custom.dart';
import 'package:shortzz/common/widget/text_field_custom.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/model/user_model/user_model.dart';
import 'package:shortzz/utilities/text_style_custom.dart';
import 'package:shortzz/utilities/theme_res.dart';
import 'package:shortzz/common/service/api/user_service.dart';

/// Self-contained email verification sheet: enter the emailed code, or resend
/// it. Deliberately independent of AuthScreenController — it is opened from a
/// post-navigation reminder, after that controller may be disposed.
class EmailVerificationSheet extends StatelessWidget {
  const EmailVerificationSheet({super.key});

  @override
  Widget build(BuildContext context) {
    final controller = Get.put(EmailVerificationSheetController());
    return Container(
      height: 400,
      margin: EdgeInsets.only(top: AppBar().preferredSize.height * 2.5),
      decoration: ShapeDecoration(
          shape: const SmoothRectangleBorder(
              borderRadius: SmoothBorderRadius.vertical(
                  top: SmoothRadius(cornerRadius: 40, cornerSmoothing: 1))),
          color: scaffoldBackgroundColor(context)),
      child: SafeArea(
        minimum: const EdgeInsets.only(bottom: 10),
        child: Column(
          children: [
            BottomSheetTopView(title: LKey.enterOtpCode.tr),
            Expanded(
              child: SingleChildScrollView(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Padding(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 20.0, vertical: 5),
                      child: Text(LKey.verifyEmailReminderCode.tr,
                          style: TextStyleCustom.outFitRegular400(
                              fontSize: 15, color: textLightGrey(context))),
                    ),
                    TextFieldCustom(
                      controller: controller.codeController,
                      title: LKey.enterOtpCode.tr,
                      keyboardType: TextInputType.number,
                    ),
                    Align(
                      alignment: AlignmentDirectional.centerEnd,
                      child: InkWell(
                        onTap: controller.resendCode,
                        child: Padding(
                          padding: const EdgeInsets.symmetric(
                              horizontal: 20.0, vertical: 8),
                          child: Text(LKey.resendCode.tr,
                              style: TextStyleCustom.outFitMedium500(
                                  fontSize: 15,
                                  color: textDarkGrey(context))),
                        ),
                      ),
                    ),
                    TextButtonCustom(
                        onTap: controller.verifyCode,
                        title: LKey.verify.tr,
                        backgroundColor: textDarkGrey(context),
                        titleColor: whitePure(context),
                        margin: const EdgeInsets.all(15)),
                  ],
                ),
              ),
            )
          ],
        ),
      ),
    );
  }
}

class EmailVerificationSheetController extends BaseController {
  TextEditingController codeController = TextEditingController();

  /// Gives the tight per-IP `auth` rate limiter (api_service.dart's 429
  /// handling) a distinct, honest message instead of the generic one.
  String _messageForError(Object e) {
    if (e.toString().contains('RateLimited')) {
      return LKey.tooManyAttempts.tr;
    }
    return LKey.somethingWentWrong.tr;
  }

  Future<void> resendCode() async {
    showLoader(barrierDismissible: false);
    try {
      final res = await UserService.instance.sendEmailVerificationCode();
      stopLoader();
      showSnackBar(res.status == true ? LKey.verificationCodeSentTo.tr : res.message);
    } catch (e, st) {
      Loggers.error('sendEmailVerificationCode failed: $e');
      FirebaseCrashlytics.instance.recordError(e, st, fatal: false);
      stopLoader();
      showSnackBar(_messageForError(e));
    }
  }

  Future<void> verifyCode() async {
    final String code = codeController.text.trim();
    if (code.isEmpty) {
      return showSnackBar(LKey.enterOtpCode.tr);
    }
    showLoader(barrierDismissible: false);
    UserModel model;
    try {
      model = await UserService.instance.verifyEmailCode(code: code);
    } catch (e, st) {
      Loggers.error('verifyEmailCode failed: $e');
      FirebaseCrashlytics.instance.recordError(e, st, fatal: false);
      stopLoader();
      return showSnackBar(_messageForError(e));
    }
    stopLoader();
    if (model.status != true) {
      return showSnackBar(model.message == 'invalid_code'
          ? LKey.invalidOtp.tr
          : model.message);
    }
    Get.back(); // close the sheet
    showSnackBar(LKey.emailVerifiedSuccessfully.tr);
  }
}

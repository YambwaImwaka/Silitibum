import 'package:figma_squircle_updated/figma_squircle.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:shortzz/common/widget/bottom_sheet_top_view.dart';
import 'package:shortzz/common/widget/text_button_custom.dart';
import 'package:shortzz/common/widget/text_field_custom.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/screen/auth_screen/auth_screen_controller.dart';
import 'package:shortzz/utilities/text_style_custom.dart';
import 'package:shortzz/utilities/theme_res.dart';

/// Second step of the password reset: the backend has emailed a code (to the
/// address itself for email identities, to the recovery email for phone
/// identities) — enter it with the new password.
class ResetPasswordCodeSheet extends StatelessWidget {
  final String identity;
  final String? maskedEmail;

  const ResetPasswordCodeSheet(
      {super.key, required this.identity, this.maskedEmail});

  @override
  Widget build(BuildContext context) {
    final controller = Get.find<AuthScreenController>();
    return Container(
      height: 520,
      margin: EdgeInsets.only(top: AppBar().preferredSize.height * 2),
      decoration: ShapeDecoration(
          shape: const SmoothRectangleBorder(
              borderRadius: SmoothBorderRadius.vertical(
                  top: SmoothRadius(cornerRadius: 40, cornerSmoothing: 1))),
          color: scaffoldBackgroundColor(context)),
      child: SafeArea(
        minimum: const EdgeInsets.only(bottom: 10),
        child: Column(
          children: [
            BottomSheetTopView(title: LKey.forgetPassword.tr),
            Expanded(
              child: SingleChildScrollView(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Padding(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 20.0, vertical: 5),
                      child: Text(
                          maskedEmail == null
                              ? LKey.enterOtpCode.tr
                              : '${LKey.otpSentTo.tr} $maskedEmail',
                          style: TextStyleCustom.outFitRegular400(
                              fontSize: 15, color: textLightGrey(context))),
                    ),
                    TextFieldCustom(
                      controller: controller.codeController,
                      title: LKey.enterOtpCode.tr,
                      keyboardType: TextInputType.number,
                    ),
                    TextFieldCustom(
                      controller: controller.passwordController,
                      title: LKey.newPassword.tr,
                      isPasswordField: true,
                    ),
                    TextFieldCustom(
                      controller: controller.confirmPassController,
                      title: LKey.reTypePassword.tr,
                      isPasswordField: true,
                    ),
                    Align(
                      alignment: AlignmentDirectional.centerEnd,
                      child: InkWell(
                        onTap: () =>
                            controller.resendPasswordResetCode(identity),
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
                        onTap: () => controller.submitPasswordReset(identity),
                        title: LKey.forgetPassword.tr,
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

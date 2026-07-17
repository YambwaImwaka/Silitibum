import 'dart:io';

import 'package:figma_squircle_updated/figma_squircle.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:shortzz/common/widget/custom_divider.dart';
import 'package:shortzz/common/widget/privacy_policy_text.dart';
import 'package:shortzz/common/widget/text_button_custom.dart';
import 'package:shortzz/common/widget/theme_blur_bg.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/screen/auth_screen/auth_screen_controller.dart';
import 'package:shortzz/screen/auth_screen/login_screen.dart';
import 'package:shortzz/screen/auth_screen/registration_screen.dart';
import 'package:shortzz/utilities/asset_res.dart';
import 'package:shortzz/utilities/text_style_custom.dart';
import 'package:shortzz/utilities/theme_res.dart';

/// TikTok-style login prompt shown when a guest taps a gated action
/// (like, comment, follow, save, gift, create, chat, notifications, tabs).
/// Provider buttons only — the credential flows live in LoginScreen /
/// RegistrationScreen.
class LoginSheet extends StatelessWidget {
  const LoginSheet({super.key});

  @override
  Widget build(BuildContext context) {
    final controller = Get.put(AuthScreenController());
    return ClipSmoothRect(
      radius: const SmoothBorderRadius.vertical(
          top: SmoothRadius(cornerRadius: 30, cornerSmoothing: 1)),
      child: Container(
        color: blackPure(context),
        child: Stack(
          children: [
            const ThemeBlurBg(),
            SafeArea(
              top: false,
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Container(
                    height: 5,
                    width: 40,
                    margin: const EdgeInsets.only(top: 12),
                    decoration: BoxDecoration(
                        color: whitePure(context).withValues(alpha: .4),
                        borderRadius: BorderRadius.circular(3)),
                  ),
                  Padding(
                    padding: const EdgeInsets.only(top: 25, left: 20, right: 20),
                    child: RichText(
                      textAlign: TextAlign.center,
                      text: TextSpan(
                        text: LKey.signIn.tr.toUpperCase(),
                        style: TextStyleCustom.unboundedBlack900(
                                fontSize: 22, color: whitePure(context))
                            .copyWith(letterSpacing: -.2),
                        children: [
                          TextSpan(
                              text: '\n${LKey.toContinue.tr}'.toUpperCase(),
                              style: TextStyleCustom.unboundedBlack900(
                                  fontSize: 22,
                                  color: whitePure(context).withValues(alpha: .5),
                                  opacity: .5))
                        ],
                      ),
                    ),
                  ),
                  Padding(
                    padding: const EdgeInsets.only(top: 12, left: 30, right: 30),
                    child: Text(
                      LKey.signInToInteract.tr,
                      textAlign: TextAlign.center,
                      style: TextStyleCustom.outFitRegular400(
                          fontSize: 16,
                          color: whitePure(context).withValues(alpha: .8)),
                    ),
                  ),
                  const SizedBox(height: 30),
                  TextButtonCustom(
                      onTap: () {
                        Get.back();
                        Get.to(() => const LoginScreen());
                      },
                      title: LKey.usePhoneOrEmail.tr,
                      btnHeight: 50,
                      horizontalMargin: 20),
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 22.0),
                    child: Row(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        CustomDivider(
                            color: whitePure(context), height: .5, width: 90),
                        Padding(
                          padding: const EdgeInsets.symmetric(horizontal: 15.0),
                          child: Text(LKey.continueWith.tr,
                              style: TextStyleCustom.outFitRegular400(
                                  fontSize: 15, color: whitePure(context))),
                        ),
                        CustomDivider(
                            color: whitePure(context), height: .5, width: 90),
                      ],
                    ),
                  ),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      if (Platform.isIOS) ...[
                        SocialBtn(
                            onTap: controller.onAppleTap, icon: AssetRes.icApple),
                        const SizedBox(width: 10),
                      ],
                      SocialBtn(
                          onTap: controller.onGoogleTap, icon: AssetRes.icGoogle),
                    ],
                  ),
                  InkWell(
                    onTap: () {
                      Get.back();
                      controller.fullNameController.clear();
                      controller.phoneController.clear();
                      controller.emailController.clear();
                      controller.passwordController.clear();
                      controller.confirmPassController.clear();
                      Get.to(() => const RegistrationScreen());
                    },
                    child: Container(
                      height: 48,
                      margin: const EdgeInsets.only(top: 22),
                      alignment: Alignment.center,
                      width: double.infinity,
                      color: whitePure(context).withValues(alpha: .2),
                      child: Text(
                        LKey.createAccountHere.tr,
                        style: TextStyleCustom.outFitRegular400(
                            color: whitePure(context), fontSize: 16),
                      ),
                    ),
                  ),
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 15.0),
                    child: PrivacyPolicyText(
                      boldTextColor: whitePure(context),
                      regularTextColor: whitePure(context).withValues(alpha: .8),
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

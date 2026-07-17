import 'dart:io';

import 'package:figma_squircle_updated/figma_squircle.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:shortzz/common/widget/bottom_sheet_top_view.dart';
import 'package:shortzz/common/widget/custom_divider.dart';
import 'package:shortzz/common/widget/custom_tab_switcher.dart';
import 'package:shortzz/common/widget/privacy_policy_text.dart';
import 'package:shortzz/common/widget/text_button_custom.dart';
import 'package:shortzz/common/widget/text_field_custom.dart';
import 'package:shortzz/common/widget/theme_blur_bg.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/screen/auth_screen/auth_screen_controller.dart';
import 'package:shortzz/screen/auth_screen/forget_password_sheet.dart';
import 'package:shortzz/screen/auth_screen/registration_screen.dart';
import 'package:shortzz/screen/edit_profile_screen/widget/phone_codes_screen.dart';
import 'package:shortzz/screen/edit_profile_screen/widget/phone_codes_screen_controller.dart';
import 'package:shortzz/utilities/asset_res.dart';
import 'package:shortzz/utilities/text_style_custom.dart';
import 'package:shortzz/utilities/theme_res.dart';

/// Sign-in with the user's choice of channel:
/// - Phone: phone + password (the SMS OTP happened once, at sign-up).
/// - Email: email + password.
/// Plus Google (and Apple on iOS). Login never creates accounts.
class LoginScreen extends StatelessWidget {
  const LoginScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final controller = Get.put(AuthScreenController());
    return Scaffold(
      resizeToAvoidBottomInset: false,
      body: Container(
        height: Get.height,
        decoration: const ShapeDecoration(
            shape: SmoothRectangleBorder(
          borderRadius: SmoothBorderRadius.vertical(
              top: SmoothRadius(cornerRadius: 0, cornerSmoothing: 1)),
        )),
        child: Stack(
          children: [
            const ThemeBlurBg(),
            SingleChildScrollView(
              child: SafeArea(
                bottom: false,
                child: Column(
                  children: [
                    Padding(
                      padding:
                          const EdgeInsets.only(left: 20, right: 20, top: 30),
                      child: Column(
                        children: [
                          Padding(
                            padding: const EdgeInsets.only(top: 30.0),
                            child: RichText(
                                textAlign: TextAlign.center,
                                text: TextSpan(
                                  text: LKey.signIn.tr.toUpperCase(),
                                  style: TextStyleCustom.unboundedBlack900(
                                    fontSize: 25,
                                    color: whitePure(context),
                                  ).copyWith(letterSpacing: -.2),
                                  children: [
                                    TextSpan(
                                        text: '\n${LKey.toContinue.tr}'
                                            .toUpperCase(),
                                        style:
                                            TextStyleCustom.unboundedBlack900(
                                                fontSize: 25,
                                                color: whitePure(context)
                                                    .withValues(alpha: .5),
                                                opacity: .5))
                                  ],
                                )),
                          ),
                          const SizedBox(height: 40),
                          CustomTabSwitcher(
                            items: const [LKey.phoneNumber, LKey.email],
                            selectedIndex: controller.authMethodIndex,
                            onTap: (index) =>
                                controller.authMethodIndex.value = index,
                            backgroundColor:
                                whitePure(context).withValues(alpha: .12),
                          ),
                          const SizedBox(height: 14),
                          Obx(
                            () => controller.authMethodIndex.value == 0
                                ? Row(
                                    children: [
                                      PhoneCodeChip(),
                                      const SizedBox(width: 10),
                                      Expanded(
                                        child: LoginSheetTextField(
                                          hintText: LKey.phoneNumber.tr,
                                          controller:
                                              controller.phoneController,
                                          keyboardType: TextInputType.phone,
                                        ),
                                      ),
                                    ],
                                  )
                                : LoginSheetTextField(
                                    hintText: LKey.enterYourEmail.tr,
                                    controller: controller.emailController,
                                    keyboardType: TextInputType.emailAddress,
                                  ),
                          ),
                          const SizedBox(height: 14),
                          LoginSheetTextField(
                            isPasswordField: true,
                            hintText: LKey.enterPassword.tr,
                            controller: controller.passwordController,
                          ),
                          Align(
                            alignment: AlignmentDirectional.centerEnd,
                            child: InkWell(
                              onTap: () {
                                if (controller.isPhoneMode) {
                                  controller.passwordController.clear();
                                  controller.confirmPassController.clear();
                                  Get.bottomSheet(
                                      const PhoneForgotPasswordSheet(),
                                      isScrollControlled: true);
                                } else {
                                  Get.bottomSheet(const ForgetPasswordSheet(),
                                          isScrollControlled: true)
                                      .then((value) => controller
                                          .forgetEmailController
                                          .clear());
                                }
                              },
                              child: Padding(
                                  padding: const EdgeInsets.symmetric(
                                      vertical: 14.0),
                                  child: Text(LKey.forgetPassword.tr,
                                      style: TextStyleCustom.outFitRegular400(
                                          fontSize: 16,
                                          color: whitePure(context)))),
                            ),
                          ),
                          Obx(
                            () => TextButtonCustom(
                                onTap: controller.authMethodIndex.value == 0
                                    ? controller.onPhoneLogin
                                    : controller.onEmailLogin,
                                title: LKey.logIn.tr,
                                btnHeight: 50,
                                horizontalMargin: 0),
                          )
                        ],
                      ),
                    ),
                    InkWell(
                      onTap: () {
                        controller.fullNameController.clear();
                        controller.emailController.clear();
                        controller.phoneController.clear();
                        controller.passwordController.clear();
                        controller.confirmPassController.clear();
                        Get.to(() => const RegistrationScreen());
                      },
                      child: Container(
                        height: 48,
                        margin: const EdgeInsets.symmetric(vertical: 25),
                        alignment: Alignment.center,
                        color: whitePure(context).withValues(alpha: .2),
                        child: Text(
                          LKey.createAccountHere.tr,
                          style: TextStyleCustom.outFitRegular400(
                              color: whitePure(context), fontSize: 16),
                        ),
                      ),
                    ),
                    Row(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        CustomDivider(
                          color: whitePure(context),
                          height: .5,
                          width: 100,
                        ),
                        Padding(
                          padding: const EdgeInsets.symmetric(horizontal: 15.0),
                          child: Text(
                            LKey.continueWith.tr,
                            style: TextStyleCustom.outFitRegular400(
                                fontSize: 16, color: whitePure(context)),
                          ),
                        ),
                        CustomDivider(
                          color: whitePure(context),
                          height: .5,
                          width: 100,
                        ),
                      ],
                    ),
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 25.0),
                      child: Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          if (Platform.isIOS)
                            SocialBtn(
                              onTap: controller.onAppleTap,
                              icon: AssetRes.icApple,
                            ),
                          if (Platform.isIOS) const SizedBox(width: 10),
                          SocialBtn(
                              onTap: controller.onGoogleTap,
                              icon: AssetRes.icGoogle),
                        ],
                      ),
                    ),
                    PrivacyPolicyText(
                      boldTextColor: whitePure(context),
                      regularTextColor:
                          whitePure(context).withValues(alpha: .8),
                    )
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Country-code selector chip for the dark login screen. Uses the same
/// PhoneCodesScreenController/PhoneCodesScreen as TextFieldCustom's prefix.
class PhoneCodeChip extends StatelessWidget {
  PhoneCodeChip({super.key});

  final PhoneCodesScreenController codesController =
      Get.isRegistered<PhoneCodesScreenController>()
          ? Get.find<PhoneCodesScreenController>()
          : Get.put(PhoneCodesScreenController());

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: () {
        Get.bottomSheet(const PhoneCodesScreen(),
                isScrollControlled: true, ignoreSafeArea: false)
            .then((value) => codesController.searchPhoneCodes(''));
      },
      child: Container(
        height: 52,
        padding: const EdgeInsets.symmetric(horizontal: 10),
        decoration: ShapeDecoration(
            shape: SmoothRectangleBorder(
              borderRadius:
                  SmoothBorderRadius(cornerRadius: 10, cornerSmoothing: 1),
              side:
                  BorderSide(color: whitePure(context).withValues(alpha: .4)),
              borderAlign: BorderAlign.inside,
            ),
            color: whitePure(context).withValues(alpha: .1)),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Obx(() => Text(
                codesController.selectedCode.value?.phoneCode == null
                    ? '+'
                    : '${codesController.selectedCode.value?.countryCode ?? ''} ${codesController.selectedCode.value?.phoneCode ?? ''}',
                style: TextStyleCustom.outFitRegular400(
                    fontSize: 16, color: whitePure(context)))),
            Icon(Icons.arrow_drop_down, color: whitePure(context), size: 24),
          ],
        ),
      ),
    );
  }
}

/// Phone accounts reset their password by re-proving phone possession with an
/// SMS OTP (no email involved).
class PhoneForgotPasswordSheet extends StatelessWidget {
  const PhoneForgotPasswordSheet({super.key});

  @override
  Widget build(BuildContext context) {
    final controller = Get.find<AuthScreenController>();
    return Container(
      height: 480,
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
                      child: Text(LKey.resetPasswordViaOtp.tr,
                          style: TextStyleCustom.outFitRegular400(
                              fontSize: 15, color: textLightGrey(context))),
                    ),
                    TextFieldCustom(
                      controller: controller.phoneController,
                      title: LKey.phoneNumber.tr,
                      isPrefixIconShow: true,
                      keyboardType: TextInputType.phone,
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
                    const SizedBox(height: 10),
                    TextButtonCustom(
                        onTap: () {
                          // Keep the sheet open: validation errors surface as
                          // snackbars above it; on success the OTP screen is
                          // pushed on top and Get.offAll cleans up after.
                          controller.onPhoneForgotPassword();
                        },
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

class LoginSheetTextField extends StatefulWidget {
  final bool isPasswordField;
  final String hintText;
  final TextEditingController controller;
  final TextInputType? keyboardType;

  const LoginSheetTextField(
      {super.key,
      this.isPasswordField = false,
      required this.hintText,
      required this.controller,
      this.keyboardType});

  @override
  State<LoginSheetTextField> createState() => _LoginSheetTextFieldState();
}

class _LoginSheetTextFieldState extends State<LoginSheetTextField> {
  bool isHide = true;

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: ShapeDecoration(
          shape: SmoothRectangleBorder(
            borderRadius:
                SmoothBorderRadius(cornerRadius: 10, cornerSmoothing: 1),
            side: BorderSide(color: whitePure(context).withValues(alpha: .4)),
            borderAlign: BorderAlign.inside,
          ),
          color: whitePure(context).withValues(alpha: .1)),
      child: TextField(
        controller: widget.controller,
        style: TextStyleCustom.outFitRegular400(
            color: whitePure(context), fontSize: 16),
        onTapOutside: (event) => FocusManager.instance.primaryFocus?.unfocus(),
        obscureText: widget.isPasswordField && isHide,
        keyboardType: widget.keyboardType ?? TextInputType.text,
        decoration: InputDecoration(
          border: InputBorder.none,
          hintText: widget.hintText,
          hintStyle: TextStyleCustom.outFitRegular400(
              color: whitePure(context), fontSize: 16),
          contentPadding: EdgeInsets.only(
              left: 10, right: 10, top: widget.isPasswordField ? 2 : 0),
          suffixIconConstraints: const BoxConstraints(),
          suffixIcon: widget.isPasswordField
              ? InkWell(
                  onTap: () {
                    isHide = !isHide;
                    setState(() {});
                  },
                  child: AnimatedSwitcher(
                    duration: const Duration(milliseconds: 250),
                    child: Image.asset(
                        isHide ? AssetRes.icEye : AssetRes.icHideEye,
                        height: 24,
                        width: 35,
                        color: whitePure(context),
                        key: UniqueKey()),
                  ),
                )
              : null,
        ),
        cursorColor: whitePure(context),
      ),
    );
  }
}

class SocialBtn extends StatelessWidget {
  final String icon;
  final VoidCallback onTap;

  const SocialBtn({super.key, required this.icon, required this.onTap});

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      child: Container(
        height: 57,
        width: 57,
        decoration:
            BoxDecoration(shape: BoxShape.circle, color: whitePure(context)),
        alignment: Alignment.center,
        child: Image.asset(icon, height: 32, width: 32),
      ),
    );
  }
}

import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:shortzz/common/widget/custom_back_button.dart';
import 'package:shortzz/common/widget/custom_tab_switcher.dart';
import 'package:shortzz/common/widget/gradient_text.dart';
import 'package:shortzz/common/widget/privacy_policy_text.dart';
import 'package:shortzz/common/widget/text_button_custom.dart';
import 'package:shortzz/common/widget/text_field_custom.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/screen/auth_screen/auth_screen_controller.dart';
import 'package:shortzz/utilities/style_res.dart';
import 'package:shortzz/utilities/text_style_custom.dart';
import 'package:shortzz/utilities/theme_res.dart';

/// Sign-up with the user's choice of channel:
/// - Phone: name + phone + password, number verified once via SMS OTP.
/// - Email: name + email + password, verification email sent (soft).
class RegistrationScreen extends StatelessWidget {
  const RegistrationScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final controller = Get.isRegistered<AuthScreenController>()
        ? Get.find<AuthScreenController>()
        : Get.put(AuthScreenController());
    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const SizedBox(height: 10),
            const CustomBackButton(
                padding: EdgeInsets.symmetric(horizontal: 20, vertical: 5)),
            const SizedBox(height: 10),
            Expanded(
                child: SingleChildScrollView(
              dragStartBehavior: DragStartBehavior.down,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Padding(
                    padding: const EdgeInsets.only(
                        left: 20.0, right: 20, top: 40, bottom: 10),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(LKey.signUp.tr.toUpperCase(),
                            style: TextStyleCustom.unboundedBlack900(
                              fontSize: 25,
                              color: textDarkGrey(context),
                            ).copyWith(letterSpacing: -.2)),
                        GradientText(LKey.startJourney.tr.toUpperCase(),
                            gradient: StyleRes.themeGradient,
                            style: TextStyleCustom.unboundedBlack900(
                              fontSize: 25,
                              color: textDarkGrey(context),
                            ).copyWith(letterSpacing: -.2)),
                      ],
                    ),
                  ),
                  Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 20.0),
                    child: CustomTabSwitcher(
                      items: const [LKey.phoneNumber, LKey.email],
                      selectedIndex: controller.authMethodIndex,
                      onTap: (index) =>
                          controller.authMethodIndex.value = index,
                    ),
                  ),
                  TextFieldCustom(
                    controller: controller.fullNameController,
                    title: LKey.fullName.tr,
                  ),
                  Obx(
                    () => controller.authMethodIndex.value == 0
                        ? TextFieldCustom(
                            controller: controller.phoneController,
                            title: LKey.phoneNumber.tr,
                            isPrefixIconShow: true,
                            keyboardType: TextInputType.phone,
                          )
                        : TextFieldCustom(
                            controller: controller.emailController,
                            title: LKey.email.tr,
                            keyboardType: TextInputType.emailAddress,
                          ),
                  ),
                  TextFieldCustom(
                    controller: controller.passwordController,
                    title: LKey.password.tr,
                    isPasswordField: true,
                  ),
                  TextFieldCustom(
                    controller: controller.confirmPassController,
                    title: LKey.reTypePassword.tr,
                    isPasswordField: true,
                  ),
                ],
              ),
            )),
            Obx(
              () => TextButtonCustom(
                  onTap: controller.authMethodIndex.value == 0
                      ? controller.onPhoneSignUp
                      : controller.onEmailSignUp,
                  title: LKey.createAccount.tr,
                  backgroundColor: textDarkGrey(context),
                  horizontalMargin: 20,
                  titleColor: whitePure(context)),
            ),
            SizedBox(height: AppBar().preferredSize.height / 1.2),
            const SafeArea(
                top: false,
                maintainBottomViewPadding: true,
                child: PrivacyPolicyText()),
          ],
        ),
      ),
    );
  }
}

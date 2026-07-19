import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:google_sign_in/google_sign_in.dart';
import 'package:shortzz/common/controller/base_controller.dart';
import 'package:shortzz/common/functions/debounce_action.dart';
import 'package:shortzz/common/manager/firebase_notification_manager.dart';
import 'package:shortzz/common/manager/logger.dart';
import 'package:shortzz/common/manager/session_manager.dart';
import 'package:shortzz/common/service/api/common_service.dart';
import 'package:shortzz/common/service/api/notification_service.dart';
import 'package:shortzz/common/service/api/user_service.dart';
import 'package:shortzz/common/service/subscription/subscription_manager.dart';
import 'package:shortzz/languages/dynamic_translations.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/model/general/settings_model.dart';
import 'package:shortzz/model/general/status_model.dart';
import 'package:shortzz/model/user_model/forgot_password_model.dart';
import 'package:shortzz/model/user_model/user_model.dart' as user;
import 'package:shortzz/model/user_model/user_model.dart';
import 'package:shortzz/screen/auth_screen/email_verification_sheet.dart';
import 'package:shortzz/screen/auth_screen/reset_password_code_sheet.dart';
import 'package:shortzz/screen/dashboard_screen/dashboard_screen.dart';
import 'package:shortzz/screen/edit_profile_screen/widget/phone_codes_screen_controller.dart';
import 'package:shortzz/utilities/text_style_custom.dart';
import 'package:shortzz/utilities/theme_res.dart';
import 'package:sign_in_with_apple/sign_in_with_apple.dart';

/// Auth flows — all credentials live in MySQL on the Laravel backend
/// (Firebase Auth removed):
///
/// - PHONE sign-up : name + phone + password → user/registerUser.
/// - PHONE login   : phone + password → user/logInUser.
/// - PHONE reset   : a code goes to the account's recovery email — there is
///   no SMS channel (an SMS gateway can slot in server-side later).
/// - EMAIL sign-up : name + email + password → registerUser; the backend
///   emails a verification code (soft: the app is usable immediately,
///   reminder until verified).
/// - EMAIL login   : email + password; reminder shown while unverified.
/// - GOOGLE/APPLE  : the provider's ID token is sent to the backend, which
///   verifies it directly against Google/Apple.
class AuthScreenController extends BaseController {
  TextEditingController fullNameController = TextEditingController();
  TextEditingController emailController = TextEditingController();
  TextEditingController forgetEmailController = TextEditingController();
  TextEditingController passwordController = TextEditingController();
  TextEditingController confirmPassController = TextEditingController();
  TextEditingController phoneController = TextEditingController();
  TextEditingController codeController = TextEditingController();

  /// Phone | Email mode on LoginScreen / RegistrationScreen (0 = phone, 1 = email).
  RxInt authMethodIndex = 0.obs;

  bool get isPhoneMode => authMethodIndex.value == 0;

  @override
  void onInit() {
    CommonService.instance.fetchGlobalSettings();
    FirebaseNotificationManager.instance;
    super.onInit();
  }

  // ---------------------------------------------------------------------
  // PHONE
  // ---------------------------------------------------------------------

  Future<void> onPhoneSignUp() async {
    if (fullNameController.text.trim().isEmpty) {
      return showSnackBar(LKey.fullNameEmpty.tr);
    }
    final String? fullPhone = _normalizedPhone();
    if (fullPhone == null) return;
    final String? password = _validatedNewPassword();
    if (password == null) return;

    await _authenticate(
        (deviceToken) => UserService.instance.registerUser(
            identity: fullPhone,
            password: password,
            loginMethod: LoginMethod.phone,
            fullName: fullNameController.text.trim(),
            deviceToken: deviceToken),
        password: password);
  }

  Future<void> onPhoneLogin() async {
    final String? fullPhone = _normalizedPhone();
    if (fullPhone == null) return;
    final String password = passwordController.text.trim();
    if (password.isEmpty) {
      return showSnackBar(LKey.enterAPassword.tr);
    }

    await _authenticate(
        (deviceToken) => UserService.instance.logInUser(
            identity: fullPhone,
            loginMethod: LoginMethod.phone,
            password: password,
            deviceToken: deviceToken),
        password: password);
  }

  Future<void> onPhoneForgotPassword() async {
    final String? fullPhone = _normalizedPhone();
    if (fullPhone == null) return;
    await startPasswordReset(fullPhone);
  }

  /// Canonical identity: '+<countryCode><national digits, no leading zeros>'.
  /// The phone string IS the account key — signup and login must produce the
  /// exact same value or the backend would see two different users.
  String? _normalizedPhone() {
    String phone =
        phoneController.text.trim().replaceAll(RegExp(r'[^0-9]'), '');
    if (phone.isEmpty) {
      showSnackBar(LKey.phoneEmpty.tr);
      return null;
    }
    String phoneCode = '';
    if (Get.isRegistered<PhoneCodesScreenController>()) {
      phoneCode = Get.find<PhoneCodesScreenController>()
              .selectedCode
              .value
              ?.phoneCode
              .replaceAll('+', '')
              .trim() ??
          '';
    }
    if (phoneCode.isEmpty) {
      showSnackBar(LKey.selectCountryCode.tr);
      return null;
    }
    phone = phone.replaceFirst(RegExp(r'^0+'), '');
    if (phone.isEmpty) {
      showSnackBar(LKey.phoneEmpty.tr);
      return null;
    }
    return '+$phoneCode$phone';
  }

  String? _validatedNewPassword() {
    final String password = passwordController.text.trim();
    if (password.isEmpty) {
      showSnackBar(LKey.enterAPassword.tr);
      return null;
    }
    if (password.length < 6) {
      showSnackBar(LKey.weakPassword.tr);
      return null;
    }
    if (confirmPassController.text.trim().isEmpty) {
      showSnackBar(LKey.confirmPasswordEmpty.tr);
      return null;
    }
    if (confirmPassController.text.trim() != password) {
      showSnackBar(LKey.passwordMismatch.tr);
      return null;
    }
    return password;
  }

  // ---------------------------------------------------------------------
  // EMAIL
  // ---------------------------------------------------------------------

  Future<void> onEmailSignUp() async {
    if (fullNameController.text.trim().isEmpty) {
      return showSnackBar(LKey.fullNameEmpty.tr);
    }
    final String email = emailController.text.trim();
    if (email.isEmpty) {
      return showSnackBar(LKey.enterEmail.tr);
    }
    if (!GetUtils.isEmail(email)) {
      return showSnackBar(LKey.invalidEmail.tr);
    }
    final String? password = _validatedNewPassword();
    if (password == null) return;

    await _authenticate(
        (deviceToken) => UserService.instance.registerUser(
            identity: email,
            password: password,
            loginMethod: LoginMethod.email,
            fullName: fullNameController.text.trim(),
            deviceToken: deviceToken),
        password: password,
        successMessage: LKey.verificationCodeSentTo.tr);
  }

  Future<void> onEmailLogin() async {
    final String email = emailController.text.trim();
    final String password = passwordController.text.trim();
    if (email.isEmpty) {
      return showSnackBar(LKey.enterEmail.tr);
    }
    if (!GetUtils.isEmail(email)) {
      return showSnackBar(LKey.invalidEmail.tr);
    }
    if (password.isEmpty) {
      return showSnackBar(LKey.enterAPassword.tr);
    }

    await _authenticate(
        (deviceToken) => UserService.instance.logInUser(
            identity: email,
            loginMethod: LoginMethod.email,
            password: password,
            deviceToken: deviceToken),
        password: password,
        remindVerifyEmail: true);
  }

  /// Email accounts — phone accounts reset via [onPhoneForgotPassword].
  Future<void> forgetPassword() async {
    final email = forgetEmailController.text.trim();
    if (email.isEmpty) {
      return showSnackBar(LKey.enterEmail.tr);
    }
    if (!GetUtils.isEmail(email)) {
      return showSnackBar(LKey.invalidEmail.tr);
    }
    await startPasswordReset(email);
  }

  // ---------------------------------------------------------------------
  // PASSWORD RESET (code to email / recovery email)
  // ---------------------------------------------------------------------

  Future<void> startPasswordReset(String identity) async {
    showLoader();
    ForgotPasswordModel res;
    try {
      res = await UserService.instance.forgotPassword(identity: identity);
    } catch (e) {
      Loggers.error('forgotPassword failed: $e');
      stopLoader();
      return showSnackBar(LKey.somethingWentWrong.tr);
    }
    stopLoader();
    if (res.status != true) {
      return showSnackBar(_friendlyAuthMessage(res.message));
    }
    codeController.clear();
    passwordController.clear();
    confirmPassController.clear();
    Get.bottomSheet(
        ResetPasswordCodeSheet(
            identity: identity, maskedEmail: res.maskedEmail),
        isScrollControlled: true);
  }

  /// Resend from inside the code sheet — sends a fresh code without stacking
  /// another sheet.
  Future<void> resendPasswordResetCode(String identity) async {
    showLoader();
    try {
      final res = await UserService.instance.forgotPassword(identity: identity);
      stopLoader();
      showSnackBar(res.status == true
          ? LKey.verificationCodeSentTo.tr
          : _friendlyAuthMessage(res.message));
    } catch (e) {
      Loggers.error('forgotPassword resend failed: $e');
      stopLoader();
      showSnackBar(LKey.somethingWentWrong.tr);
    }
  }

  Future<void> submitPasswordReset(String identity) async {
    final String code = codeController.text.trim();
    if (code.isEmpty) {
      return showSnackBar(LKey.enterOtpCode.tr);
    }
    final String? newPassword = _validatedNewPassword();
    if (newPassword == null) return;

    showLoader();
    StatusModel res;
    try {
      res = await UserService.instance.resetPasswordWithCode(
          identity: identity, code: code, newPassword: newPassword);
    } catch (e) {
      Loggers.error('resetPasswordWithCode failed: $e');
      stopLoader();
      return showSnackBar(LKey.somethingWentWrong.tr);
    }
    stopLoader();
    if (res.status != true) {
      return showSnackBar(_friendlyAuthMessage(res.message));
    }

    // The backend invalidated all sessions; sign straight back in with the
    // new password (also closes the reset sheets via the offAll navigation).
    await _authenticate(
        (deviceToken) => UserService.instance.logInUser(
            identity: identity,
            loginMethod:
                identity.startsWith('+') ? LoginMethod.phone : LoginMethod.email,
            password: newPassword,
            deviceToken: deviceToken),
        password: newPassword,
        successMessage: LKey.passwordUpdated.tr);
  }

  // ---------------------------------------------------------------------
  // GOOGLE / APPLE
  // ---------------------------------------------------------------------

  void onGoogleTap() async {
    showLoader();
    GoogleSignInAccount? account;
    try {
      final GoogleSignIn googleSignIn = GoogleSignIn.instance;
      await googleSignIn.initialize();
      account = await googleSignIn.authenticate();
    } catch (e) {
      Loggers.error(e);
      stopLoader();
      return;
    }

    final String? idToken = account.authentication.idToken;
    if (idToken == null) {
      stopLoader();
      return showSnackBar(LKey.somethingWentWrong.tr);
    }
    final String email = account.email;
    await _authenticate(
        (deviceToken) => UserService.instance.logInUser(
            identity: email,
            loginMethod: LoginMethod.google,
            idToken: idToken,
            fullName: account?.displayName ?? email.split('@')[0],
            deviceToken: deviceToken),
        isLoaderRunning: true);
  }

  void onAppleTap() async {
    showLoader();
    AuthorizationCredentialAppleID appleCredential;
    try {
      appleCredential = await SignInWithApple.getAppleIDCredential(
        scopes: [
          AppleIDAuthorizationScopes.email,
          AppleIDAuthorizationScopes.fullName
        ],
      );
    } catch (e) {
      Loggers.error(e);
      stopLoader();
      return;
    }

    final String? identityToken = appleCredential.identityToken;
    if (identityToken == null) {
      stopLoader();
      return showSnackBar(LKey.somethingWentWrong.tr);
    }
    // Apple reveals the email only on the first grant; the backend keys the
    // account on the token's stable sub claim, this identity is a fallback.
    final String identity = appleCredential.email ??
        'apple:${appleCredential.userIdentifier ?? ''}';
    final String? fullname = [
      appleCredential.givenName,
      appleCredential.familyName
    ].whereType<String>().join(' ').trim().isEmpty
        ? appleCredential.email?.split('@')[0]
        : '${appleCredential.givenName ?? ''} ${appleCredential.familyName ?? ''}'
            .trim();
    await _authenticate(
        (deviceToken) => UserService.instance.logInUser(
            identity: identity,
            loginMethod: LoginMethod.apple,
            identityToken: identityToken,
            fullName: fullname,
            deviceToken: deviceToken),
        isLoaderRunning: true);
  }

  // ---------------------------------------------------------------------
  // SHARED AUTH PIPELINE
  // ---------------------------------------------------------------------

  /// Runs an auth request with the FCM device token attached, handles
  /// failures, stores the password for later confirmations, fires post-login
  /// side effects and navigates into the app.
  Future<void> _authenticate(
      Future<UserModel> Function(String? deviceToken) request,
      {String? password,
      String? successMessage,
      bool remindVerifyEmail = false,
      bool isLoaderRunning = false}) async {
    if (!isLoaderRunning) showLoader();

    // FCM token is optional: devices without Play Services (or with
    // notifications denied) must still be able to sign in — push just
    // won't target them.
    String? deviceToken;
    try {
      deviceToken =
          await FirebaseNotificationManager.instance.getNotificationToken();
    } catch (e) {
      Loggers.error('FCM token unavailable, continuing without it: $e');
    }

    UserModel model;
    try {
      model = await request(deviceToken);
    } catch (e) {
      Loggers.error('auth request failed: $e');
      stopLoader();
      showSnackBar(LKey.somethingWentWrong.tr);
      return;
    }
    stopLoader();

    final user.User? data = model.data;
    if (model.status != true || data == null) {
      showSnackBar(_friendlyAuthMessage(model.message));
      return;
    }

    if (password != null) {
      SessionManager.instance.setPassword(password);
    }
    _postLoginSideEffects(data);
    _navigateScreen(data);
    if (successMessage != null) {
      _showSnackBarAfterNavigate(successMessage);
    } else if (remindVerifyEmail && data.emailVerifiedAt == null) {
      _showVerifyEmailReminder();
    }
  }

  /// Maps the backend's stable failure tokens to localized messages; any
  /// other message is shown as-is.
  String _friendlyAuthMessage(String? message) {
    switch (message) {
      case 'account_not_found':
        return LKey.noAccountFoundCreateOne.tr;
      case 'incorrect_password':
        return LKey.incorrectPassword.tr;
      case 'account_exists':
        return LKey.accountExistsLogin.tr;
      case 'no_recovery_email':
        return LKey.noRecoveryEmail.tr;
      case 'invalid_code':
        return LKey.invalidOtp.tr;
      default:
        return message ?? LKey.somethingWentWrong.tr;
    }
  }

  void _postLoginSideEffects(user.User data) {
    Setting? setting = SessionManager.instance.getSettings();
    if (data.isDummy == 0 &&
        data.newRegister == true &&
        setting?.registrationBonusStatus == 1) {
      final translations = Get.find<DynamicTranslations>();
      final languageData = translations.keys[data.appLanguage] ?? {};

      NotificationService.instance.pushNotification(
          title: languageData[LKey.registrationBonusTitle] ??
              LKey.registrationBonusTitle.tr,
          body: languageData[LKey.registrationBonusDescription] ??
              LKey.registrationBonusDescription.tr,
          type: NotificationType.other,
          deviceType: data.device,
          token: data.deviceToken,
          authorizationToken: data.token?.authToken);
    }
    SubscriptionManager.shared.login('${data.id}');
  }

  void _showSnackBarAfterNavigate(String message) {
    Future.delayed(const Duration(milliseconds: 900), () {
      showSnackBar(message, second: 4);
    });
  }

  /// Soft verification: usable while unverified, reminded until confirmed.
  /// The button opens a self-contained code sheet (it must not depend on
  /// this controller — navigation disposes it).
  void _showVerifyEmailReminder() {
    Future.delayed(const Duration(milliseconds: 900), () {
      if (Get.isSnackbarOpen) return;
      Get.rawSnackbar(
        backgroundColor: blackPure(Get.context!),
        margin: const EdgeInsets.symmetric(horizontal: 10),
        padding: const EdgeInsets.all(15),
        borderRadius: 10,
        duration: const Duration(seconds: 5),
        snackPosition: SnackPosition.TOP,
        messageText: Text(LKey.verifyEmailReminderCode.tr,
            style: TextStyleCustom.outFitRegular400(
                color: whitePure(Get.context!), fontSize: 16)),
        mainButton: TextButton(
          onPressed: () {
            stopSnackBar();
            Get.bottomSheet(const EmailVerificationSheet(),
                isScrollControlled: true);
          },
          child: Text(LKey.verify.tr,
              style: TextStyleCustom.outFitMedium500(
                  color: whitePure(Get.context!), fontSize: 16)),
        ),
      );
    });
  }

  void _navigateScreen(user.User? data) {
    DebounceAction.shared.call(() async {
      SessionManager.instance.setLogin(true);
      SessionManager.instance.setUser(data);
      Get.offAll(() => DashboardScreen(myUser: data));
    }, milliseconds: 250);
  }
}

import 'package:firebase_auth/firebase_auth.dart';
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
import 'package:shortzz/model/user_model/user_model.dart' as user;
import 'package:shortzz/screen/auth_screen/otp_verification_screen.dart';
import 'package:shortzz/screen/dashboard_screen/dashboard_screen.dart';
import 'package:shortzz/screen/edit_profile_screen/widget/phone_codes_screen_controller.dart';
import 'package:shortzz/utilities/const_res.dart';
import 'package:shortzz/utilities/text_style_custom.dart';
import 'package:shortzz/utilities/theme_res.dart';
import 'package:sign_in_with_apple/sign_in_with_apple.dart';

/// Auth flows (all credentials live in Firebase, the backend only maps a
/// verified identity to an app user):
///
/// - PHONE sign-up : name + phone + password → SMS OTP proves possession →
///   the phone Firebase user gets an internal alias-email credential
///   (<digits>@phone.silitibum.com + password) linked to it.
/// - PHONE login   : phone + password → plain Firebase password sign-in
///   against the alias credential. No SMS cost per login.
/// - PHONE reset   : OTP re-proves possession → updatePassword.
/// - EMAIL sign-up : name + email + password → createUser + verification
///   email (soft: the app is usable immediately, reminder until verified).
/// - EMAIL login   : email + password; reminder shown while unverified.
/// - GOOGLE/APPLE  : unchanged provider flows.
class AuthScreenController extends BaseController {
  TextEditingController fullNameController = TextEditingController();
  TextEditingController emailController = TextEditingController();
  TextEditingController forgetEmailController = TextEditingController();
  TextEditingController passwordController = TextEditingController();
  TextEditingController confirmPassController = TextEditingController();
  TextEditingController phoneController = TextEditingController();

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

    final String fullname = fullNameController.text.trim();
    Get.to(() => OtpVerificationScreen(
          phoneNumber: fullPhone,
          onVerified: (userCred) =>
              _completePhoneSignUp(userCred, fullPhone, password, fullname),
        ));
  }

  Future<void> onPhoneLogin() async {
    final String? fullPhone = _normalizedPhone();
    if (fullPhone == null) return;
    final String password = passwordController.text.trim();
    if (password.isEmpty) {
      return showSnackBar(LKey.enterAPassword.tr);
    }

    showLoader();
    UserCredential? credential;
    try {
      credential = await FirebaseAuth.instance.signInWithEmailAndPassword(
          email: _aliasEmailFor(fullPhone), password: password);
    } on FirebaseAuthException catch (e) {
      stopLoader();
      if (e.code == 'user-not-found') {
        return showSnackBar(LKey.noAccountFoundCreateOne.tr);
      }
      // 'invalid-credential' covers wrong password AND unknown accounts when
      // Firebase email-enumeration protection is on.
      return showSnackBar(LKey.incorrectPassword.tr);
    }

    if (credential.user == null) {
      stopLoader();
      return showSnackBar(LKey.noAccountFoundCreateOne.tr);
    }
    SessionManager.instance.setPassword(password);
    final user.User? data = await _registration(
        identity: fullPhone, loginMethod: LoginMethod.phone);
    stopLoader();
    if (data != null) _navigateScreen(data);
  }

  Future<void> onPhoneForgotPassword() async {
    final String? fullPhone = _normalizedPhone();
    if (fullPhone == null) return;
    final String? newPassword = _validatedNewPassword();
    if (newPassword == null) return;

    Get.to(() => OtpVerificationScreen(
          phoneNumber: fullPhone,
          onVerified: (userCred) => _completePhoneReset(userCred, fullPhone, newPassword),
        ));
  }

  Future<void> _completePhoneSignUp(UserCredential userCred, String fullPhone,
      String password, String fullname) async {
    final firebaseUser = userCred.user;
    if (firebaseUser == null) return;

    showLoader();
    try {
      await firebaseUser.linkWithCredential(EmailAuthProvider.credential(
          email: _aliasEmailFor(fullPhone), password: password));
    } on FirebaseAuthException catch (e) {
      if (e.code == 'provider-already-linked') {
        // This phone already signed up before. OTP just re-proved possession,
        // so treating the new password as a reset is safe.
        try {
          await firebaseUser.updatePassword(password);
        } catch (err) {
          Loggers.error('updatePassword on re-signup failed: $err');
        }
      } else if (e.code == 'email-already-in-use' ||
          e.code == 'credential-already-in-use') {
        stopLoader();
        return showSnackBar(LKey.accountExistsLogin.tr);
      } else {
        stopLoader();
        Loggers.error('linkWithCredential failed: ${e.code} ${e.message}');
        return showSnackBar(e.message);
      }
    }

    SessionManager.instance.setPassword(password);
    final user.User? data = await _registration(
        identity: fullPhone, loginMethod: LoginMethod.phone, fullname: fullname);
    stopLoader();
    if (data != null) _navigateScreen(data);
  }

  Future<void> _completePhoneReset(
      UserCredential userCred, String fullPhone, String newPassword) async {
    final firebaseUser = userCred.user;
    if (firebaseUser == null) return;

    showLoader();
    try {
      // If the phone user never had the alias credential (edge case), link it
      // instead of updating.
      final hasAlias = firebaseUser.providerData
          .any((p) => p.providerId == EmailAuthProvider.PROVIDER_ID);
      if (hasAlias) {
        await firebaseUser.updatePassword(newPassword);
      } else {
        await firebaseUser.linkWithCredential(EmailAuthProvider.credential(
            email: _aliasEmailFor(fullPhone), password: newPassword));
      }
    } on FirebaseAuthException catch (e) {
      stopLoader();
      Loggers.error('phone password reset failed: ${e.code} ${e.message}');
      return showSnackBar(e.message);
    }

    SessionManager.instance.setPassword(newPassword);
    final user.User? data = await _registration(
        identity: fullPhone, loginMethod: LoginMethod.phone);
    stopLoader();
    if (data != null) {
      showSnackBar(LKey.passwordUpdated.tr);
      _navigateScreen(data);
    }
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

  String _aliasEmailFor(String fullPhone) =>
      '${fullPhone.replaceAll(RegExp(r'[^0-9]'), '')}@$phoneAliasEmailDomain';

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

    showLoader();
    final UserCredential? credential = await createUserWithEmailAndPassword();
    if (credential?.user == null) return; // it showed the error already

    // THE fix for "verification emails were not coming": the template never
    // called sendEmailVerification, yet login demanded emailVerified == true.
    try {
      await credential!.user!.sendEmailVerification();
    } catch (e) {
      Loggers.error('sendEmailVerification failed: $e');
    }

    final user.User? data = await _registration(
        identity: email,
        loginMethod: LoginMethod.email,
        fullname: fullNameController.text.trim());
    stopLoader();
    if (data != null) {
      _navigateScreen(data);
      _showSnackBarAfterNavigate(LKey.verificationEmailSentTo.tr);
    }
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

    showLoader();
    final UserCredential? credential = await signInWithEmailAndPassword();
    if (credential?.user == null) {
      stopLoader();
      return;
    }

    SessionManager.instance.setPassword(password);
    String fullname = credential!.user!.displayName ?? email.split('@')[0];
    final user.User? data = await _registration(
        identity: email, loginMethod: LoginMethod.email, fullname: fullname);
    stopLoader();
    if (data != null) {
      _navigateScreen(data);
      // Soft verification: usable while unverified, reminded until confirmed.
      if (credential.user!.emailVerified == false) {
        _showVerifyEmailReminder();
      }
    }
  }

  void _showSnackBarAfterNavigate(String message) {
    Future.delayed(const Duration(milliseconds: 900), () {
      showSnackBar(message, second: 4);
    });
  }

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
        messageText: Text(LKey.verifyEmailReminder.tr,
            style: TextStyleCustom.outFitRegular400(
                color: whitePure(Get.context!), fontSize: 16)),
        mainButton: TextButton(
          onPressed: () async {
            try {
              await FirebaseAuth.instance.currentUser?.sendEmailVerification();
              stopSnackBar();
              showSnackBar(LKey.verificationEmailSentTo.tr);
            } catch (e) {
              Loggers.error('resend verification failed: $e');
            }
          },
          child: Text(LKey.resend.tr,
              style: TextStyleCustom.outFitMedium500(
                  color: whitePure(Get.context!), fontSize: 16)),
        ),
      );
    });
  }

  // ---------------------------------------------------------------------
  // GOOGLE / APPLE
  // ---------------------------------------------------------------------

  void onGoogleTap() async {
    showLoader();
    UserCredential? credential;
    try {
      credential = await signInWithGoogle();
    } catch (e) {
      Loggers.error(e);
      Get.back();
    }

    if (credential?.user == null) return;
    user.User? data = await _registration(
        identity: credential?.user?.email ?? '',
        loginMethod: LoginMethod.google,
        fullname: credential?.user?.displayName ??
            credential?.user?.email?.split('@')[0]);
    Get.back();
    if (data != null) {
      _navigateScreen(data);
    }
  }

  void onAppleTap() async {
    showLoader();
    UserCredential? credential;
    try {
      credential = await signInWithApple();
      Loggers.info(
          'EMAIL : ${credential.user?.email} FULLNAME : ${credential.user?.displayName ?? credential.user?.email?.split('@')[0]}');
    } catch (e) {
      Loggers.error(e);
      Get.back();
    }
    if (credential?.user == null) return;
    user.User? data = await _registration(
        identity: credential?.user?.email ?? '',
        loginMethod: LoginMethod.apple,
        fullname: credential?.user?.displayName ??
            credential?.user?.email?.split('@')[0]);
    Get.back();
    if (data != null) {
      _navigateScreen(data);
    }
  }

  // ---------------------------------------------------------------------
  // BACKEND REGISTRATION (register-or-login by verified identity)
  // ---------------------------------------------------------------------

  Future<user.User?> _registration(
      {required String identity,
      required LoginMethod loginMethod,
      String? fullname}) async {
    // FCM token is optional: devices without Play Services (or with
    // notifications denied) must still be able to register — push just
    // won't target them.
    String? deviceToken;
    try {
      deviceToken =
          await FirebaseNotificationManager.instance.getNotificationToken();
    } catch (e) {
      Loggers.error('FCM token unavailable, continuing without it: $e');
    }

    // Identity proof for the backend (verified server-side against the
    // claimed identity). All flows sign into Firebase before reaching here.
    String? firebaseIdToken;
    try {
      firebaseIdToken = await FirebaseAuth.instance.currentUser?.getIdToken();
    } catch (e) {
      Loggers.error('getIdToken failed: $e');
    }

    // A thrown network/server error here used to escape every auth flow and
    // leave the loader dialog up forever — the app looked frozen.
    user.User? userData;
    try {
      userData = await UserService.instance.logInUser(
          identity: identity,
          loginMethod: loginMethod,
          deviceToken: deviceToken,
          fullName: fullname,
          firebaseToken: firebaseIdToken);
    } catch (e) {
      Loggers.error('logInUser failed: $e');
      stopLoader();
      showSnackBar(LKey.somethingWentWrong.tr);
      return null;
    }

    Setting? setting = SessionManager.instance.getSettings();
    if (userData?.isDummy == 0 &&
        userData?.newRegister == true &&
        setting?.registrationBonusStatus == 1) {
      final translations = Get.find<DynamicTranslations>();
      final languageData = translations.keys[userData?.appLanguage] ?? {};

      NotificationService.instance.pushNotification(
          title: languageData[LKey.registrationBonusTitle] ??
              LKey.registrationBonusTitle.tr,
          body: languageData[LKey.registrationBonusDescription] ??
              LKey.registrationBonusDescription.tr,
          type: NotificationType.other,
          deviceType: userData?.device,
          token: userData?.deviceToken,
          authorizationToken: userData?.token?.authToken);
    }
    SubscriptionManager.shared.login('${userData?.id}');
    return userData;
  }

  // ---------------------------------------------------------------------
  // FIREBASE HELPERS
  // ---------------------------------------------------------------------

  Future<UserCredential?> createUserWithEmailAndPassword() async {
    try {
      final credential = await FirebaseAuth.instance
          .createUserWithEmailAndPassword(
              email: emailController.text.trim(),
              password: passwordController.text.trim());
      SessionManager.instance.setPassword(passwordController.text.trim());
      return credential;
    } on FirebaseAuthException catch (e) {
      stopLoader();
      Loggers.error(e.message);
      if (e.code == 'weak-password') {
        showSnackBar(LKey.weakPassword.tr);
      } else if (e.code == 'email-already-in-use') {
        showSnackBar(LKey.accountExists.tr);
      } else {
        showSnackBar(e.message);
      }
      return null;
    }
  }

  Future<UserCredential?> signInWithEmailAndPassword() async {
    try {
      final credential = await FirebaseAuth.instance.signInWithEmailAndPassword(
          email: emailController.text.trim(),
          password: passwordController.text.trim());
      return credential;
    } on FirebaseAuthException catch (e) {
      stopLoader();
      if (e.code == 'user-not-found') {
        showSnackBar(LKey.noUserFound.tr);
      } else if (e.code == 'wrong-password' || e.code == 'invalid-credential') {
        // 'invalid-credential' = wrong password or unknown account (Firebase
        // email-enumeration protection merges the two).
        showSnackBar(LKey.incorrectPassword.tr);
      } else {
        showSnackBar(e.message);
      }
      return null;
    } catch (e) {
      Loggers.error(e);
      return null;
    }
  }

  Future<UserCredential> signInWithGoogle() async {
    final GoogleSignIn googleSignIn = GoogleSignIn.instance;
    googleSignIn.initialize();
    GoogleSignInAccount account = await googleSignIn.authenticate();

    // Create a new credential
    final credential =
        GoogleAuthProvider.credential(idToken: account.authentication.idToken);

    // Once signed in, return the UserCredential
    return await FirebaseAuth.instance.signInWithCredential(credential);
  }

  Future<UserCredential> signInWithApple() async {
    // Request credential for the currently signed in Apple account.
    final appleCredential = await SignInWithApple.getAppleIDCredential(
      scopes: [
        AppleIDAuthorizationScopes.email,
        AppleIDAuthorizationScopes.fullName
      ],
    );

    // Create an `OAuthCredential` from the credential returned by Apple.
    final oauthCredential = OAuthProvider("apple.com").credential(
        idToken: appleCredential.identityToken,
        accessToken: appleCredential.authorizationCode);

    return await FirebaseAuth.instance.signInWithCredential(oauthCredential);
  }

  /// Email accounts only — phone accounts reset via [onPhoneForgotPassword].
  void forgetPassword() async {
    final email = forgetEmailController.text.trim();
    if (email.isEmpty) {
      showSnackBar(LKey.enterEmail.tr);
      return;
    }
    showLoader();
    try {
      await FirebaseAuth.instance.sendPasswordResetEmail(email: email);
      stopLoader();
      Get.back(); // Close the BottomSheet
      showSnackBar(LKey.resetPasswordLinkSent.tr);
    } on FirebaseAuthException catch (e) {
      stopLoader();
      showSnackBar(e.message ?? "An error occurred. Please try again.");
    }
  }

  void _navigateScreen(user.User? data) {
    DebounceAction.shared.call(() async {
      SessionManager.instance.setLogin(true);
      SessionManager.instance.setUser(data);
      Get.offAll(() => DashboardScreen(myUser: data));
    }, milliseconds: 250);
  }
}

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
import 'package:shortzz/screen/dashboard_screen/dashboard_screen.dart';
import 'package:sign_in_with_apple/sign_in_with_apple.dart';
import 'package:shortzz/screen/edit_profile_screen/widget/phone_codes_screen_controller.dart';

class AuthScreenController extends BaseController {
  TextEditingController fullNameController = TextEditingController();
  TextEditingController emailController = TextEditingController();
  TextEditingController forgetEmailController = TextEditingController();
  TextEditingController passwordController = TextEditingController();
  TextEditingController confirmPassController = TextEditingController();
  // Phone sign-up controllers
  TextEditingController phoneController = TextEditingController();
  String? _verificationId;

  @override
  void onInit() {
    CommonService.instance.fetchGlobalSettings();
    FirebaseNotificationManager.instance;
    super.onInit();
  }

  Future<void> onLogin() async {
    final email = emailController.text.trim();
    final password = passwordController.text.trim();

    if (email.isEmpty) {
      return showSnackBar(LKey.enterEmail.tr);
    }
    if (password.isEmpty) {
      return showSnackBar(LKey.enterAPassword.tr);
    }

    showLoader();

    if (GetUtils.isEmail(email)) {
      final UserCredential? credential = await signInWithEmailAndPassword();

      if (credential == null) {
        stopLoader();
        return showSnackBar(LKey.userNotFound.tr);
      }

      if (credential.user?.emailVerified == false) {
        stopLoader();
        return showSnackBar(LKey.verifyEmailFirst.tr);
      }

      String fullname = credential.user?.displayName ?? email.split('@')[0];
      final user.User? data = await _registration(
          identity: email, loginMethod: LoginMethod.email, fullname: fullname, loginVia: LoginVia.loginInUser);
      stopLoader();

      if (data != null) {
        _navigateScreen(data);
      }
    } else {
      final user.User? data = await _registration(
          identity: email, loginMethod: LoginMethod.email, loginVia: LoginVia.logInFakeUser, password: password);
      stopLoader();

      if (data != null) {
        _navigateScreen(data);
      }
    }
  }

  Future<void> onCreateAccount() async {
    // Phone-number-only signup using Firebase OTP
    if (fullNameController.text.trim().isEmpty) {
      return showSnackBar(LKey.fullNameEmpty.tr);
    }
    if (phoneController.text.trim().isEmpty) {
      return showSnackBar(LKey.phoneNumber.tr);
    }

    // Build full phone number with selected country code if available
    String phone = phoneController.text.trim();
    String phoneCode = '';
    try {
    if (Get.isRegistered<PhoneCodesScreenController>()) {
      final pc = Get.find<PhoneCodesScreenController>();
        phoneCode = pc.selectedCode.value?.phoneCode?.replaceAll('+', '') ?? '';
      }
    } catch (_) {}

    String fullPhone = phoneCode.isNotEmpty ? '+$phoneCode$phone' : phone;

    showLoader();
    try {
      await FirebaseAuth.instance.verifyPhoneNumber(
        phoneNumber: fullPhone,
        verificationCompleted: (PhoneAuthCredential credential) async {
          // Auto-retrieval or instant verification
          final userCred = await FirebaseAuth.instance.signInWithCredential(credential);
          if (userCred.user != null) {
            // Register with backend
            final user.User? data = await _registration(
                identity: fullPhone, loginMethod: LoginMethod.phone, fullname: fullNameController.text.trim(), loginVia: LoginVia.loginInUser);
            stopLoader();
            if (data != null) _navigateScreen(data);
          }
        },
        verificationFailed: (FirebaseAuthException e) {
          stopLoader();
          Loggers.error('Phone verification failed: ${e.message}');
          showSnackBar(e.message);
        },
        codeSent: (String verificationId, int? resendToken) {
          stopLoader();
          _verificationId = verificationId;
          _showOtpDialog(fullPhone);
        },
        codeAutoRetrievalTimeout: (String verificationId) {
          _verificationId = verificationId;
        },
        timeout: const Duration(seconds: 60),
      );
    } catch (e) {
      stopLoader();
      Loggers.error(e);
      showSnackBar('Failed to send OTP. Please try again.');
    }
  }

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
        fullname: credential?.user?.displayName ?? credential?.user?.email?.split('@')[0],
        loginVia: LoginVia.loginInUser);
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
        fullname: credential?.user?.displayName ?? credential?.user?.email?.split('@')[0],
        loginVia: LoginVia.loginInUser);
    Get.back();
    if (data != null) {
      _navigateScreen(data);
    }
  }

  Future<user.User?> _registration(
      {required String identity,
      required LoginMethod loginMethod,
      String? fullname,
      required LoginVia loginVia,
      String? password}) async {
    String? deviceToken = await FirebaseNotificationManager.instance.getNotificationToken();
    if (deviceToken == null) return null;

    user.User? userData;
    switch (loginVia) {
      case LoginVia.loginInUser:
        userData = await UserService.instance
            .logInUser(identity: identity, loginMethod: loginMethod, deviceToken: deviceToken, fullName: fullname);
      case LoginVia.logInFakeUser:
        userData = await UserService.instance
            .logInFakeUser(identity: identity, loginMethod: loginMethod, deviceToken: deviceToken, password: password);
    }

    Setting? setting = SessionManager.instance.getSettings();
    if (userData?.isDummy == 0 && userData?.newRegister == true && setting?.registrationBonusStatus == 1) {
      final translations = Get.find<DynamicTranslations>();
      final languageData = translations.keys[userData?.appLanguage] ?? {};

      NotificationService.instance.pushNotification(
          title: languageData[LKey.registrationBonusTitle] ?? LKey.registrationBonusTitle.tr,
          body: languageData[LKey.registrationBonusDescription] ?? LKey.registrationBonusDescription.tr,
          type: NotificationType.other,
          deviceType: userData?.device,
          token: userData?.deviceToken,
          authorizationToken: userData?.token?.authToken);
    }
    SubscriptionManager.shared.login('${userData?.id}');
    if (userData != null) {
      // Subscribe My Following Ids For Live streaming notification
      return userData;
    }
    return null;
  }

  Future<UserCredential?> createUserWithEmailAndPassword() async {
    try {
      final credential = await FirebaseAuth.instance
          .createUserWithEmailAndPassword(email: emailController.text.trim(), password: passwordController.text.trim());
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
      final credential = await FirebaseAuth.instance
          .signInWithEmailAndPassword(email: emailController.text.trim(), password: passwordController.text.trim());
      return credential;
    } on FirebaseAuthException catch (e) {
      stopLoader();
      if (e.code == 'user-not-found') {
        showSnackBar(LKey.noUserFound.tr);
        Loggers.info(LKey.noUserFound.tr);
      } else if (e.code == 'wrong-password') {
        showSnackBar(LKey.incorrectPassword.tr);
        Loggers.info(LKey.incorrectPassword.tr);
      }
      return null;
    } catch (e) {
      return null;
    }
  }

  Future<UserCredential> signInWithGoogle() async {
    final GoogleSignIn googleSignIn = GoogleSignIn.instance;
    googleSignIn.initialize();
    GoogleSignInAccount account = await googleSignIn.authenticate();

    // Create a new credential
    final credential = GoogleAuthProvider.credential(idToken: account.authentication.idToken);

    // Once signed in, return the UserCredential
    return await FirebaseAuth.instance.signInWithCredential(credential);
  }

  Future<UserCredential> signInWithApple() async {
    // Request credential for the currently signed in Apple account.
    final appleCredential = await SignInWithApple.getAppleIDCredential(
      scopes: [AppleIDAuthorizationScopes.email, AppleIDAuthorizationScopes.fullName],
    );

    // Create an `OAuthCredential` from the credential returned by Apple.
    final oauthCredential = OAuthProvider("apple.com")
        .credential(idToken: appleCredential.identityToken, accessToken: appleCredential.authorizationCode);

    return await FirebaseAuth.instance.signInWithCredential(oauthCredential);
  }

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

  void _showOtpDialog(String phone) {
    final codeController = TextEditingController();
    Get.dialog(AlertDialog(
      title: Text('Enter OTP'),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text('A verification code has been sent to $phone'),
          const SizedBox(height: 10),
          TextField(
            controller: codeController,
            keyboardType: TextInputType.number,
            decoration: const InputDecoration(hintText: 'OTP'),
          )
        ],
      ),
      actions: [
        TextButton(
            onPressed: () {
              Get.back();
            },
            child: Text('Cancel')),
        TextButton(
            onPressed: () async {
              final code = codeController.text.trim();
              if (code.isEmpty || _verificationId == null) {
                showSnackBar('Please enter the OTP');
                return;
              }
              showLoader();
              try {
                final credential = PhoneAuthProvider.credential(
                    verificationId: _verificationId!, smsCode: code);
                final userCred = await FirebaseAuth.instance.signInWithCredential(credential);
                if (userCred.user != null) {
                  final user.User? data = await _registration(
                      identity: phone,
                      loginMethod: LoginMethod.phone,
                      fullname: fullNameController.text.trim(),
                      loginVia: LoginVia.loginInUser);
                  stopLoader();
                  Get.back(); // close dialog
                  if (data != null) _navigateScreen(data);
                } else {
                  stopLoader();
                  showSnackBar('OTP verification failed');
                }
              } catch (e) {
                stopLoader();
                Loggers.error(e);
                showSnackBar('OTP verification failed');
              }
            },
            child: Text('Verify'))
      ],
    ));
  }
}

enum LoginVia { loginInUser, logInFakeUser }

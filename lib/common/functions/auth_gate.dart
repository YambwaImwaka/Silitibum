import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:shortzz/common/manager/session_manager.dart';
import 'package:shortzz/screen/auth_screen/login_sheet.dart';

/// Single gate for every action that needs a signed-in user (like, comment,
/// follow, save, gift, create, chat, notifications, Messages/Profile tabs).
///
/// Usage — first statement of the guarded method, before any optimistic UI
/// mutation or API call:
///
///   if (!AuthGate.check()) return;
class AuthGate {
  AuthGate._();

  static bool _isSheetShowing = false;

  static bool get isLoggedIn => SessionManager.instance.isLogin();

  /// Returns true when a user is signed in; otherwise opens the login sheet
  /// (TikTok-style) and returns false so the caller aborts the action.
  static bool check() {
    if (isLoggedIn) return true;
    if (!_isSheetShowing) {
      _isSheetShowing = true;
      Get.bottomSheet(
        const LoginSheet(),
        isScrollControlled: true,
        backgroundColor: Colors.transparent,
      ).whenComplete(() => _isSheetShowing = false);
    }
    return false;
  }
}

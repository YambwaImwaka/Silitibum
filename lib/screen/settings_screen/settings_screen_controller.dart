import 'package:get/get.dart';
import 'package:google_sign_in/google_sign_in.dart';
import 'package:shortzz/common/controller/base_controller.dart';
import 'package:shortzz/common/controller/app_user_cache_controller.dart';
import 'package:shortzz/common/service/realtime/realtime_service.dart';
import 'package:shortzz/common/manager/logger.dart';
import 'package:shortzz/common/manager/session_manager.dart';
import 'package:shortzz/common/service/api/user_service.dart';
import 'package:shortzz/common/widget/confirmation_dialog.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/model/general/settings_model.dart';
import 'package:shortzz/model/general/status_model.dart';
import 'package:shortzz/common/widget/restart_widget.dart';
import 'package:shortzz/model/user_model/user_model.dart';

class SettingsScreenController extends BaseController {
  Rx<User?> myUser = Rx<User?>(null);
  Rx<Setting?> settings = Rx<Setting?>(null);
  Rx<WhoCanSeePost> selectedWhoCanSeePost = WhoCanSeePost.values.first.obs;
  RxBool isUpdateApiCalled = false.obs;

  @override
  void onInit() {
    super.onInit();
    initData();
  }

  void initData() {
    myUser.value = SessionManager.instance.getUser();
    settings.value = SessionManager.instance.getSettings();
    if (myUser.value?.whoCanViewPost == 0) {
      selectedWhoCanSeePost.value = WhoCanSeePost.values.first;
    } else {
      selectedWhoCanSeePost.value = WhoCanSeePost.values[1];
    }

    // For refresh user data only
    UserService.instance.fetchUserDetails();
  }

  void onChangedWhoCanSeePost(WhoCanSeePost? value) async {
    isUpdateApiCalled.value = true;

    selectedWhoCanSeePost.value = value ?? WhoCanSeePost.values.first;
    await UserService.instance.updateUserDetails(whoCanSeePost: value?.value);
    isUpdateApiCalled.value = false;
  }

  onChangedToggle(bool value, SettingToggle settingToggle) async {
    isUpdateApiCalled.value = true;
    await UserService.instance.updateUserDetails(
        notifyPostLike:
            settingToggle == SettingToggle.notifyPostLike ? value : null,
        notifyPostComment:
            settingToggle == SettingToggle.notifyPostComment ? value : null,
        notifyFollow:
            settingToggle == SettingToggle.notifyFollow ? value : null,
        notifyMention:
            settingToggle == SettingToggle.notifyMention ? value : null,
        notifyGiftReceived:
            settingToggle == SettingToggle.notifyGiftReceived ? value : null,
        notifyChat: settingToggle == SettingToggle.notifyChat ? value : null,
        receiveMessage:
            settingToggle == SettingToggle.receiveMessage ? value : null,
        showMyFollowing:
            settingToggle == SettingToggle.showMyFollowings ? value : null);
    isUpdateApiCalled.value = false;
    // For update user value
    myUser.value = SessionManager.instance.getUser();
  }

  void onDeleteAccount() {
    Get.bottomSheet(ConfirmationSheet(
        onTap: () async {
          showLoader(barrierDismissible: true);
          // Password accounts must confirm with their password (stored at
          // login); the backend verifies it before deleting. Social accounts
          // send none.
          StatusModel model = await UserService.instance
              .deleteMyAccount(password: SessionManager.instance.getPassword());
          stopLoader();
          if (model.status == true) {
            AppUserCacheController.instance.deleteUser(myUser.value?.id);
            RealtimeService.instance.disconnect();
            SessionManager.instance.clear();
            // Back to splash → guest Dashboard (TikTok-style: no forced login)
            RestartWidget.restartApp(Get.context!);
          } else {
            showSnackBar(model.message == 'incorrect_password'
                ? LKey.incorrectPassword.tr
                : model.message);
          }
        },
        description: LKey.deleteAccountMessage.tr,
        description2: LKey.proceedConfirmation.tr,
        title: LKey.deleteYourAccount.tr));
  }

  void onLogout() {
    Get.bottomSheet(ConfirmationSheet(
      onTap: () async {
        showLoader();
        try {
          StatusModel result = await UserService.instance.logoutUser();
          if (result.status == true) {
            try {
              await GoogleSignIn.instance.signOut();
            } catch (e) {
              Loggers.error('Google signOut failed: $e');
            }
            RealtimeService.instance.disconnect();
            SessionManager.instance.clearSomeKey();
            // Back to splash → guest Dashboard (TikTok-style: no forced login)
            RestartWidget.restartApp(Get.context!);
          } else {
            showSnackBar(result.message);
          }
        } catch (e) {
          showSnackBar('$e');
        } finally {
          stopLoader();
        }
      },
      description: LKey.logoutConfirmation.tr,
      description2: LKey.proceedConfirmation.tr,
      title: LKey.logoutTitle.tr,
    ));
  }
}

enum WhoCanSeePost {
  everyone,
  followersOnly;

  String get title {
    switch (this) {
      case WhoCanSeePost.everyone:
        return LKey.everyone.tr;
      case WhoCanSeePost.followersOnly:
        return LKey.followersOnly.tr;
    }
  }

  String get value {
    switch (this) {
      case WhoCanSeePost.everyone:
        return '0';
      case WhoCanSeePost.followersOnly:
        return '1';
    }
  }
}

enum SettingToggle {
  showMyFollowings,
  receiveMessage,
  notifyPostLike,
  notifyPostComment,
  notifyFollow,
  notifyMention,
  notifyGiftReceived,
  notifyChat;
}

import 'dart:io';

import 'package:image_picker/image_picker.dart';
import 'package:shortzz/common/controller/app_user_cache_controller.dart';
import 'package:shortzz/common/manager/session_manager.dart';
import 'package:shortzz/common/service/api/api_service.dart';
import 'package:shortzz/common/service/utils/params.dart';
import 'package:shortzz/common/service/utils/web_service.dart';
import 'package:shortzz/model/general/status_model.dart';
import 'package:shortzz/model/user_model/block_user_model.dart';
import 'package:shortzz/model/user_model/follower_model.dart';
import 'package:shortzz/model/user_model/following_model.dart';
import 'package:shortzz/model/user_model/forgot_password_model.dart';
import 'package:shortzz/model/user_model/links_model.dart';
import 'package:shortzz/model/user_model/user_model.dart';
import 'package:shortzz/model/user_model/users_model.dart';
import 'package:shortzz/screen/edit_profile_screen/widget/add_edit_link_sheet.dart';
import 'package:shortzz/utilities/app_res.dart';

enum LoginMethod {
  email,
  google,
  apple,
  phone;

  String title() {
    switch (this) {
      case LoginMethod.email:
        return 'email';
      case LoginMethod.google:
        return 'google';
      case LoginMethod.apple:
        return 'apple';
      case LoginMethod.phone:
        return 'phone';
    }
  }
}

class UserService {
  UserService._();

  static final UserService instance = UserService._();

  /// Login with MySQL-native credentials: password (phone/email), Google
  /// [idToken] or Apple [identityToken] — the backend verifies the credential
  /// directly (no Firebase). Returns the full model so callers can map the
  /// failure tokens (account_not_found / incorrect_password) to messages.
  Future<UserModel> logInUser({
    String? fullName,
    required String identity,
    String? deviceToken,
    required LoginMethod loginMethod,
    String? password,
    String? idToken,
    String? identityToken,
  }) async {
    UserModel model = await ApiService.instance.call(
        url: WebService.user.loginInUser,
        param: {
          Params.fullname: fullName,
          Params.identity: identity,
          Params.deviceToken: deviceToken,
          Params.device: Platform.isAndroid ? 0 : 1,
          Params.loginMethod: loginMethod.title(),
          Params.password: password,
          Params.idToken: idToken,
          Params.identityToken: identityToken,
        },
        fromJson: UserModel.fromJson);

    _storeSession(model);
    return model;
  }

  /// Signup for the password channels (phone / email). Never an upsert: an
  /// identity that already holds a password gets account_exists back.
  Future<UserModel> registerUser({
    String? fullName,
    required String identity,
    required String password,
    required LoginMethod loginMethod,
    String? deviceToken,
  }) async {
    UserModel model = await ApiService.instance.call(
        url: WebService.user.registerUser,
        param: {
          Params.fullname: fullName,
          Params.identity: identity,
          Params.password: password,
          Params.deviceToken: deviceToken,
          Params.device: Platform.isAndroid ? 0 : 1,
          Params.loginMethod: loginMethod.title(),
        },
        fromJson: UserModel.fromJson);

    _storeSession(model);
    return model;
  }

  void _storeSession(UserModel model) {
    if (model.status == true) {
      Future.delayed(const Duration(milliseconds: 100), () {
        SessionManager.instance.setUser(model.data);
        SessionManager.instance.setAuthToken(model.data?.token);
      });
    }
  }

  Future<ForgotPasswordModel> forgotPassword({required String identity}) async {
    return ApiService.instance.call(
        url: WebService.user.forgotPassword,
        param: {Params.identity: identity},
        fromJson: ForgotPasswordModel.fromJson);
  }

  Future<StatusModel> resetPasswordWithCode(
      {required String identity,
      required String code,
      required String newPassword}) async {
    return ApiService.instance.call(
        url: WebService.user.resetPasswordWithCode,
        param: {
          Params.identity: identity,
          Params.code: code,
          Params.newPassword: newPassword,
        },
        fromJson: StatusModel.fromJson);
  }

  Future<StatusModel> sendEmailVerificationCode() async {
    return ApiService.instance.call(
        url: WebService.user.sendEmailVerificationCode,
        fromJson: StatusModel.fromJson);
  }

  /// On success the returned payload carries the refreshed user (with
  /// email_verified_at set) and is stored in the session.
  Future<UserModel> verifyEmailCode({required String code}) async {
    UserModel model = await ApiService.instance.call(
        url: WebService.user.verifyEmailCode,
        param: {Params.code: code},
        fromJson: UserModel.fromJson);
    if (model.status == true && model.data != null) {
      SessionManager.instance.setUser(model.data);
    }
    return model;
  }

  Future<StatusModel> changePassword(
      {String? oldPassword, required String newPassword}) async {
    return ApiService.instance.call(
        url: WebService.user.changePassword,
        param: {
          Params.oldPassword: oldPassword,
          Params.newPassword: newPassword,
        },
        fromJson: StatusModel.fromJson);
  }

  /// Password accounts confirm deletion with their password (replaces the old
  /// Firebase re-auth); social accounts pass nothing.
  Future<StatusModel> deleteMyAccount({String? password}) async {
    StatusModel response = await ApiService.instance.call(
        url: WebService.user.deleteMyAccount,
        param: {Params.password: password},
        fromJson: StatusModel.fromJson);
    return response;
  }

  Future<StatusModel> logoutUser() async {
    StatusModel response = await ApiService.instance
        .call(url: WebService.user.logOutUser, fromJson: StatusModel.fromJson);
    return response;
  }

  Future<User?> fetchUserDetails({int? userId, Function()? onError}) async {
    UserModel userModel = await ApiService.instance.call(
        url: WebService.user.fetchUserDetails,
        param: {Params.userId: userId ?? SessionManager.instance.getUserID()},
        fromJson: UserModel.fromJson,
        onError: onError);
    if (userModel.status == true &&
        userId == SessionManager.instance.getUserID()) {
      SessionManager.instance.setUser(userModel.data);
    }
    return userModel.data;
  }

  Future<User?> updateUserDetails(
      {XFile? profilePhoto,
      String? fullname,
      String? userName,
      String? bio,
      String? email,
      String? phoneNumber,
      int? mobileCountryCode,
      String? countryCode,
      String? country,
      String? appLanguage,
      bool? showMyFollowing,
      bool? receiveMessage,
      bool? notifyPostLike,
      bool? notifyPostComment,
      bool? notifyFollow,
      bool? notifyMention,
      bool? notifyGiftReceived,
      bool? notifyChat,
      List<int>? savedMusicIds,
      double? lat,
      double? lon,
      String? whoCanSeePost,
      String? appLastUsed,
      String? region,
      String? regionName,
      String? timezone,
      int? isVerify}) async {
    UserModel userModel = await ApiService.instance.multiPartCallApi(
        url: WebService.user.updateUserDetails,
        filesMap: {
          Params.profilePhoto: [profilePhoto]
        },
        param: {
          Params.fullname: fullname,
          Params.username: userName,
          Params.bio: bio,
          Params.userEmail: email,
          Params.userMobileNo: phoneNumber,
          Params.country: country,
          Params.countryCode: countryCode,
          Params.whoCanViewPost: whoCanSeePost,
          Params.mobileCountryCode: mobileCountryCode,
          if (isVerify != null) Params.isVerify: isVerify,
          if (receiveMessage != null)
            Params.receiveMessage: receiveMessage ? 1 : 0,
          if (showMyFollowing != null)
            Params.showMyFollowing: showMyFollowing ? 1 : 0,
          if (notifyPostLike != null)
            Params.notifyPostLike: notifyPostLike ? 1 : 0,
          if (notifyPostComment != null)
            Params.notifyPostComment: notifyPostComment ? 1 : 0,
          if (notifyFollow != null) Params.notifyFollow: notifyFollow ? 1 : 0,
          if (notifyMention != null)
            Params.notifyMention: notifyMention ? 1 : 0,
          if (notifyGiftReceived != null)
            Params.notifyGiftReceived: notifyGiftReceived ? 1 : 0,
          if (notifyChat != null) Params.notifyChat: notifyChat ? 1 : 0,
          if (savedMusicIds != null)
            Params.savedMusicIds: savedMusicIds.join(','),
          if (appLanguage != null) Params.appLanguage: appLanguage,
          if (lat != null) Params.lat: lat,
          if (lon != null) Params.lon: lon,
          if (appLastUsed != null) Params.appLastUsedAt: appLastUsed,
          if (region != null) Params.region: region,
          if (regionName != null) Params.regionName: regionName,
          if (timezone != null) Params.timezone: timezone
        },
        fromJson: UserModel.fromJson);
    if (userModel.status == true) {
      SessionManager.instance.setUser(userModel.data);
      AppUserCacheController.instance.updateUser(userModel.data);
    }
    return userModel.data;
  }

  Future<StatusModel> checkUsernameAvailability(
      {required String userName}) async {
    return await ApiService.instance.call(
        url: WebService.user.checkUsernameAvailability,
        param: {Params.username: userName},
        fromJson: StatusModel.fromJson);
  }

  Future<LinksModel> addEditDeleteUserLink(
      {String? title,
      String? urlLink,
      int? linkId,
      required LinkType linkType}) async {
    String url;

    switch (linkType) {
      case LinkType.add:
        url = WebService.user.addUserLink;
      case LinkType.edit:
        url = WebService.user.editeUserLink;
      case LinkType.delete:
        url = WebService.user.deleteUserLink;
    }

    LinksModel model = await ApiService.instance.call(
        url: url,
        fromJson: LinksModel.fromJson,
        param: {
          Params.linkId: linkId,
          Params.title: title,
          Params.url: urlLink
        });
    return model;
  }

  Future<List<User>> searchUsers(
      {int? lastItemId, String keyWord = '', required int limit}) async {
    UsersModel model = await ApiService.instance.call(
        url: WebService.user.searchUsers,
        param: {
          if (lastItemId != null) Params.lastItemId: lastItemId,
          Params.limit: limit,
          if (keyWord.isNotEmpty) Params.keyword: keyWord,
        },
        fromJson: UsersModel.fromJson);
    return model.data ?? [];
  }

  Future<List<Follower>> fetchMyFollowers(
      {required int lastItemId, required int? userId}) async {
    bool isMe = userId == SessionManager.instance.getUserID();
    String url = isMe
        ? WebService.user.fetchMyFollowers
        : WebService.user.fetchUserFollowers;

    FollowerModel model = await ApiService.instance.call(
        url: url,
        param: {
          Params.limit: AppRes.paginationLimit,
          if (lastItemId != -1) Params.lastItemId: lastItemId,
          if (!isMe) Params.userId: userId,
        },
        fromJson: FollowerModel.fromJson);
    return model.data ?? [];
  }

  Future<List<Following>> fetchMyFollowing(
      {required int lastItemId, required int? userId}) async {
    bool isMe = userId == SessionManager.instance.getUserID();
    String url = isMe
        ? WebService.user.fetchMyFollowings
        : WebService.user.fetchUserFollowings;

    FollowingModel model = await ApiService.instance.call(
        url: url,
        param: {
          Params.limit: AppRes.paginationLimit,
          if (lastItemId != -1) Params.lastItemId: lastItemId,
          if (!isMe) Params.userId: userId,
        },
        fromJson: FollowingModel.fromJson);
    return model.data ?? [];
  }

  Future<StatusModel> followUser({required int userId}) async {
    StatusModel model = await ApiService.instance.call(
      url: WebService.user.followUser,
      param: {Params.userId: userId},
      fromJson: StatusModel.fromJson,
    );
    return model;
  }

  Future<StatusModel> unFollowUser({required int userId}) async {
    StatusModel model = await ApiService.instance.call(
      url: WebService.user.unFollowUser,
      param: {Params.userId: userId},
      fromJson: StatusModel.fromJson,
    );
    return model;
  }

  Future<StatusModel> unBlockUser({required int userId}) async {
    StatusModel model = await ApiService.instance.call(
        url: WebService.user.unBlockUser,
        param: {Params.userId: userId},
        fromJson: StatusModel.fromJson);

    return model;
  }

  Future<StatusModel> blockUser({required int userId}) async {
    StatusModel model = await ApiService.instance.call(
        url: WebService.user.blockUser,
        param: {Params.userId: userId},
        fromJson: StatusModel.fromJson);
    return model;
  }

  Future<StatusModel> reportPost(
      {required int userId,
      required String reason,
      required String description}) async {
    StatusModel model = await ApiService.instance.call(
        url: WebService.user.reportUser,
        param: {
          Params.userId: userId,
          Params.reason: reason,
          Params.description: description,
        },
        fromJson: StatusModel.fromJson);
    return model;
  }

  Future<List<BlockUsers>> fetchMyBlockedUsers() async {
    BlockUserModel response = await ApiService.instance.call(
      url: WebService.user.fetchMyBlockedUsers,
      fromJson: BlockUserModel.fromJson,
    );
    return response.data ?? [];
  }

  Future<void> updateLastUsedAt() async {
    await ApiService.instance.call(url: WebService.user.updateLastUsedAt);
  }
}

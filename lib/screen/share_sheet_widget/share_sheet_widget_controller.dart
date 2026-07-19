import 'dart:convert';
import 'dart:io';

import 'package:flutter/cupertino.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_cache_manager/flutter_cache_manager.dart';
import 'package:gal/gal.dart';
import 'package:get/get.dart';
import 'package:retrytech_plugin/retrytech_plugin.dart';
import 'package:shortzz/common/controller/base_controller.dart';
import 'package:shortzz/common/extensions/common_extension.dart';
import 'package:shortzz/common/extensions/list_extension.dart';
import 'package:shortzz/common/extensions/string_extension.dart';
import 'package:shortzz/common/manager/logger.dart';
import 'package:shortzz/common/manager/screenshot_manager.dart';
import 'package:shortzz/common/manager/session_manager.dart';
import 'package:shortzz/common/service/api/chat_service.dart';
import 'package:shortzz/common/service/api/post_service.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/model/chat/chat_thread.dart';
import 'package:shortzz/model/chat/message_data.dart';
import 'package:shortzz/model/general/settings_model.dart';
import 'package:shortzz/model/general/status_model.dart';
import 'package:shortzz/model/post_story/post_model.dart';
import 'package:shortzz/model/user_model/user_model.dart';
import 'package:shortzz/screen/share_sheet_widget/share_sheet_widget.dart';
import 'package:shortzz/screen/share_sheet_widget/widget/more_user_sheet.dart';
import 'package:shortzz/utilities/app_res.dart';

class ShareSheetWidgetController extends BaseController {
  User? myUser = SessionManager.instance.getUser();
  RxList<ChatThread> chatsUsers = <ChatThread>[].obs;
  RxList<ChatThread> filterChatsUsers = <ChatThread>[].obs;
  RxList<ChatThread> selectedConversation = <ChatThread>[].obs;
  Post? post;

  Function()? onCallBack;
  final RetrytechPlugin _retrytechPlugin = RetrytechPlugin();

  Setting? get setting => SessionManager.instance.getSettings();

  ShareSheetWidgetController(this.post, this.onCallBack);

  Rx<String> waterMarkPath = ''.obs;
  GlobalKey screenShotKey = GlobalKey();

  @override
  void onInit() {
    super.onInit();
    _fetchUsers();
  }

  @override
  void onReady() {
    DefaultCacheManager()
        .getSingleFile(setting?.watermarkImage?.addBaseURL() ?? '')
        .then((value) {
      waterMarkPath.value = value.path;
      Future.delayed(const Duration(milliseconds: 250), () {
        ScreenshotManager.captureScreenshot(screenShotKey).then((value) {
          waterMarkPath.value = value?.path ?? '';
        });
      });
    });
    super.onReady();
  }

  void _fetchUsers() async {
    if (!SessionManager.instance.isLogin()) return;
    try {
      final page = await ChatService.instance.fetchThreads(limit: 50);
      final approved = page.threads
          .where((thread) => thread.chatType == ChatType.approved)
          .toList();
      chatsUsers.assignAll(approved);
      filterChatsUsers.assignAll(approved);
    } catch (e) {
      Loggers.error('share sheet fetchThreads failed: $e');
    }
  }

  void onUserTap(ChatThread conversation) {
    if (selectedConversation.contains(conversation)) {
      selectedConversation.remove(conversation);
      return;
    } else {
      if (selectedConversation.length >= AppRes.shareChatLimit) {
        return showSnackBar(LKey.shareLimitMessage
            .trParams({'share_chat_limit': AppRes.shareChatLimit.toString()}));
      }
      selectedConversation.add(conversation);
    }
  }

  void onMoreTap() {
    Get.bottomSheet(const MoreUserSheet(), isScrollControlled: true);
  }

  onSearchUser(String value) {
    filterChatsUsers.value =
        chatsUsers.search(value, (p0) => p0.chatUser?.username ?? '');
  }

  void onShareSheetBottomBtnTap(ShareOption type, String link,
      {Post? post}) async {
    final postId = post?.id;

    Future<void> _handleUrlLaunch(String url, {String? fallbackUrl}) async {
      final result = await url.lunchUrl;
      Loggers.info('Handle Url launch : ${result.message}');

      Get.back();
      if (result.status == true) {
        if (post != null) {
          increaseShareCount(postId);
        }
      } else if (result.message == 'ACTIVITY_NOT_FOUND' &&
          fallbackUrl != null) {
        fallbackUrl.lunchUrl;
      }
    }

    switch (type) {
      case ShareOption.whatsapp:
        await _handleUrlLaunch(type.value(link), fallbackUrl: AppRes.whatsappPlayStoreLink);
        break;

      case ShareOption.instagram:
        if (Platform.isIOS) {
          final result = await type.value(link).lunchUrl;
          Get.back();
          if (result.status == true) {
            increaseShareCount(postId);
          } else if (result.message == 'ACTIVITY_NOT_FOUND') {
            showSnackBar('instagramNotInstalled');
          }
        } else {
          try {
            final success = await _retrytechPlugin.shareToInstagram(link);
            if (success == true) {
              increaseShareCount(postId);
            }
          } on PlatformException {
            await AppRes.instagramPlayStoreLink.lunchUrl;
          } finally {
            Get.back();
          }
        }
        break;

      case ShareOption.telegram:
        await _handleUrlLaunch(
          type.value(link),
          fallbackUrl: AppRes.telegramPlayStoreLink,
        );
        break;

      case ShareOption.download:
        _downloadReel(post);
        break;

      case ShareOption.share:
        // Future implementation
        break;

      case ShareOption.more:
        // Future implementation
        break;

      case ShareOption.copy:
        // Future implementation
        break;
    }
  }

  void _downloadReel(Post? reel) async {
    Get.back();
    final videoUrl = reel?.video?.addBaseURL() ?? '';
    if (videoUrl.isEmpty) {
      return Loggers.error('Video not found');
    }

    try {
      // Download video file
      String videoInputPath =
          (await DefaultCacheManager().getSingleFile(videoUrl)).path;
      final localPath = await PlatformPathExtension.localPath;
      String finalOutput = videoInputPath;
      // Watermarking (if enabled)
      final isWatermarkEnabled = setting?.watermarkStatus == 1;

      if (isWatermarkEnabled) {
        String outputPath = '${localPath}watermark_video.mp4';

        if (waterMarkPath.isEmpty) {
          return showSnackBar(LKey.downloadingFailed.tr);
        }
        bool? result = await _retrytechPlugin.addWaterMarkInVideo(
            inputPath: videoInputPath,
            thumbnailPath: waterMarkPath.value,
            username: '@${reel?.user?.username ?? AppRes.appName}',
            outputPath: outputPath);

        if (result == true) {
          finalOutput = outputPath;
        }
      }

      // Save to gallery
      await Gal.putVideo(finalOutput);
      stopSnackBar();
      showSnackBar(LKey.downloadCompletedSuccessfully.tr);
      Loggers.info(LKey.downloadCompletedSuccessfully.tr);
    } on GalException catch (_) {
      stopSnackBar();
      showSnackBar(LKey.downloadCompletedSuccessfully.tr);
      Loggers.info(LKey.downloadCompletedSuccessfully.tr);
    } catch (e) {
      Loggers.error('Download error: $e');
    }
  }

  void onSendChat(Post? post) async {
    Get.back();
    showSnackBar(LKey.postSentSuccessfully.tr);
    final controller = Get.find<ShareSheetWidgetController>();
    for (var element in controller.selectedConversation) {
      try {
        await ChatService.instance.sendMessage(
            threadId: element.threadId,
            receiverId: element.threadId == null ? element.userId : null,
            type: MessageType.post,
            postMessage: jsonEncode(post?.toJsonForChat()));
        increaseShareCount(post?.id);
      } catch (e) {
        Loggers.error('share to chat failed: $e');
      }
    }
  }

  void increaseShareCount(int? postId) async {
    StatusModel response =
        await PostService.instance.increaseShareCount(postId: postId);
    if (response.status == true) {
      onCallBack?.call();
    }
  }
}

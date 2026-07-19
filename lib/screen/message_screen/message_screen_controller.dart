import 'dart:async';

import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:shortzz/common/controller/app_user_cache_controller.dart';
import 'package:shortzz/common/controller/base_controller.dart';
import 'package:shortzz/common/manager/logger.dart';
import 'package:shortzz/common/manager/session_manager.dart';
import 'package:shortzz/common/service/api/chat_service.dart';
import 'package:shortzz/common/service/realtime/realtime_service.dart';
import 'package:shortzz/common/widget/confirmation_dialog.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/model/chat/chat_thread.dart';
import 'package:shortzz/model/user_model/user_model.dart';
import 'package:shortzz/screen/dashboard_screen/dashboard_screen_controller.dart';

/// Thread list (chats + requests tabs) over the MySQL backend. Realtime
/// user-channel events reorder the list live; a slow poll covers WS outages.
class MessageScreenController extends BaseController {
  List<String> chatCategories = [LKey.chats.tr, LKey.requests.tr];
  RxInt selectedChatCategory = 0.obs;
  PageController pageController = PageController();
  User? myUser = SessionManager.instance.getUser();
  RxList<ChatThread> chatsUsers = <ChatThread>[].obs;
  RxList<ChatThread> requestsUsers = <ChatThread>[].obs;
  final dashboardController = Get.find<DashboardScreenController>();
  final appUserCacheController = Get.find<AppUserCacheController>();

  StreamSubscription<RealtimeEvent>? _realtimeSub;
  Timer? _pollTimer;
  RxBool hasMore = true.obs;

  @override
  void onInit() {
    super.onInit();
    pageController = PageController(initialPage: selectedChatCategory.value);
    fetchThreads(refresh: true);
    _realtimeSub = RealtimeService.instance.events.listen(_onRealtimeEvent);
    _pollTimer = Timer.periodic(const Duration(seconds: 15), (_) {
      if (!RealtimeService.instance.isConnected) fetchThreads(refresh: true);
    });
  }

  @override
  void onClose() {
    _realtimeSub?.cancel();
    _pollTimer?.cancel();
    super.onClose();
  }

  void onPageChanged(int index) {
    selectedChatCategory.value = index;
  }

  Future<void> fetchThreads({bool refresh = false}) async {
    if (isLoading.value) return;
    isLoading.value = true;
    try {
      final page = await ChatService.instance.fetchThreads(
          lastMsgAt: refresh ? null : _oldestLastMsgAt());
      if (refresh) {
        chatsUsers.clear();
        requestsUsers.clear();
      }
      for (final thread in page.threads) {
        _upsertThread(thread);
      }
      hasMore.value = page.threads.length >= 20;
    } catch (e) {
      Loggers.error('fetchThreads failed: $e');
    } finally {
      isLoading.value = false;
    }
  }

  int? _oldestLastMsgAt() {
    final all = [...chatsUsers, ...requestsUsers]
        .map((e) => e.lastMsgAt ?? 0)
        .where((e) => e > 0);
    return all.isEmpty ? null : all.reduce((a, b) => a < b ? a : b);
  }

  void _upsertThread(ChatThread thread) {
    chatsUsers.removeWhere((element) => element.userId == thread.userId);
    requestsUsers.removeWhere((element) => element.userId == thread.userId);
    (thread.chatType == ChatType.approved ? chatsUsers : requestsUsers)
        .add(thread);
    _sort(chatsUsers);
    _sort(requestsUsers);
  }

  void _sort(RxList<ChatThread> list) {
    list.sort((a, b) => (b.lastMsgAt ?? 0).compareTo(a.lastMsgAt ?? 0));
  }

  void _onRealtimeEvent(RealtimeEvent event) {
    // Only user-channel events matter here (thread list + badge).
    if (!event.channel.startsWith('private-user.')) return;
    switch (event.name) {
      case 'message.sent':
      case 'thread.updated':
        final threadJson = event.data['thread'];
        if (threadJson is Map) {
          _upsertThread(
              ChatThread.fromServerJson(threadJson.cast<String, dynamic>()));
        }
        dashboardController.refreshUnreadCounts();
        break;
      case 'thread.deleted':
        final int? threadId = event.data['thread_id'];
        chatsUsers.removeWhere((element) => element.threadId == threadId);
        requestsUsers.removeWhere((element) => element.threadId == threadId);
        break;
    }
  }

  void onLongPress(ChatThread chatConversation) {
    Get.bottomSheet(ConfirmationSheet(
      title: LKey.deleteChatUserTitle
          .trParams({'user_name': chatConversation.chatUser?.username ?? ''}),
      description: LKey.deleteChatUserDescription.tr,
      onTap: () async {
        if (chatConversation.threadId == null) return;
        showLoader();
        try {
          await ChatService.instance
              .deleteThread(threadId: chatConversation.threadId!);
          chatsUsers.removeWhere(
              (element) => element.threadId == chatConversation.threadId);
          requestsUsers.removeWhere(
              (element) => element.threadId == chatConversation.threadId);
          dashboardController.refreshUnreadCounts();
        } catch (e) {
          Loggers.error('deleteThread failed: $e');
        } finally {
          stopLoader();
        }
      },
    ));
  }
}

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

  /// Drives the list's initial spinner. Deliberately NOT [isLoading]: that
  /// flag belongs to `BaseController`'s modal loader, so a delete-thread
  /// confirmation in flight used to make the concurrent 15s poll a no-op and
  /// the spinner flicker on every long-press.
  RxBool isThreadsLoading = false.obs;

  /// Set when a fetch fails so the UI can offer a retry instead of silently
  /// rendering the "no chats yet" empty state — a timed-out or 500 response
  /// used to be indistinguishable from an genuinely empty inbox.
  RxnString threadsError = RxnString();

  /// Re-entrancy guard for [fetchThreads] alone.
  bool _isFetchingThreads = false;

  /// Local filter over the loaded threads. The search field above the list was
  /// previously a `const CustomSearchTextField()` with no controller and no
  /// `onChanged` — it looked functional but filtered nothing.
  final TextEditingController searchController = TextEditingController();
  final RxString searchQuery = ''.obs;

  /// Threads for the given tab, narrowed by [searchQuery].
  List<ChatThread> visibleThreads({required bool requests}) {
    final source = requests ? requestsUsers : chatsUsers;
    final query = searchQuery.value.trim().toLowerCase();
    if (query.isEmpty) return source;
    return source.where((thread) {
      final user = thread.chatUser;
      return (user?.username ?? '').toLowerCase().contains(query) ||
          (user?.fullname ?? '').toLowerCase().contains(query);
    }).toList();
  }

  void onSearchChanged(String value) => searchQuery.value = value;

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
    _releaseThreadBindings();
    searchController.dispose();
    super.onClose();
  }

  void onPageChanged(int index) {
    selectedChatCategory.value = index;
  }

  Future<void> fetchThreads({bool refresh = false}) async {
    if (_isFetchingThreads) return;
    _isFetchingThreads = true;
    isThreadsLoading.value = true;
    try {
      final page = await ChatService.instance.fetchThreads(
          lastMsgAt: refresh ? null : _oldestLastMsgAt());
      if (refresh) {
        _releaseThreadBindings();
        chatsUsers.clear();
        requestsUsers.clear();
      }
      for (final thread in page.threads) {
        _upsertThread(thread);
      }
      hasMore.value = page.threads.length >= 20;
      threadsError.value = null;
    } catch (e) {
      Loggers.error('fetchThreads failed: $e');
      // Only surface the failure when there is nothing on screen. A failed
      // background poll must not blow away a list the user is reading.
      if (chatsUsers.isEmpty && requestsUsers.isEmpty) {
        threadsError.value = LKey.somethingWentWrong.tr;
      }
    } finally {
      isThreadsLoading.value = false;
      _isFetchingThreads = false;
    }
  }

  /// Retry entry point for the error state.
  Future<void> retryFetchThreads() async {
    threadsError.value = null;
    await fetchThreads(refresh: true);
  }

  void _releaseThreadBindings() {
    for (final thread in [...chatsUsers, ...requestsUsers]) {
      thread.disposeChatUserBinding();
    }
  }

  int? _oldestLastMsgAt() {
    final all = [...chatsUsers, ...requestsUsers]
        .map((e) => e.lastMsgAt ?? 0)
        .where((e) => e > 0);
    return all.isEmpty ? null : all.reduce((a, b) => a < b ? a : b);
  }

  void _upsertThread(ChatThread thread) {
    _removeThreadWhere((element) => element.userId == thread.userId);
    // Bound once here rather than in the list's itemBuilder, where it ran on
    // every rebuild and scroll frame.
    thread.bindChatUser();
    (thread.chatType == ChatType.approved ? chatsUsers : requestsUsers)
        .add(thread);
    _sort(chatsUsers);
    _sort(requestsUsers);
  }

  /// Removes matching threads from both tabs, releasing their cache listeners.
  void _removeThreadWhere(bool Function(ChatThread) test) {
    for (final list in [chatsUsers, requestsUsers]) {
      for (final thread in list.where(test)) {
        thread.disposeChatUserBinding();
      }
      list.removeWhere(test);
    }
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
        _removeThreadWhere((element) => element.threadId == threadId);
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
        await withLoader(() async {
          await ChatService.instance
              .deleteThread(threadId: chatConversation.threadId!);
          _removeThreadWhere(
              (element) => element.threadId == chatConversation.threadId);
          dashboardController.refreshUnreadCounts();
        });
      },
    ));
  }
}

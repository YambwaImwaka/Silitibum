import 'package:get/get.dart';
import 'package:shortzz/common/controller/app_user_cache_controller.dart';
import 'package:shortzz/common/extensions/list_extension.dart';
import 'package:shortzz/common/manager/session_manager.dart';
import 'package:shortzz/common/service/api/user_service.dart';
import 'package:shortzz/model/livestream/app_user.dart';

/// A 1:1 chat thread as seen by the current user (MySQL-backed; the server
/// sends a neutral both-sides payload and [ChatThread.fromServerJson] maps it
/// to this perspective).
class ChatThread {
  /// Server thread id — null until the first message creates the thread.
  int? threadId;

  /// The other participant's user id.
  int? userId;

  /// Legacy string id kept for widget compatibility (sort keys, tags).
  String? id;

  /// My unread message count in this thread.
  int? msgCount;
  ChatType? chatType;
  String? requestType;
  String? lastMsg;
  int? lastMsgUserId;

  /// Epoch ms of the last message (list ordering + pagination cursor).
  int? lastMsgAt;

  /// The other side's last-read message id (read receipts).
  int? peerLastReadMessageId;
  String? conversationId;
  int? deletedId;
  bool? isDeleted;
  bool? iAmBlocked;
  bool? iBlocked;

  ChatThread({
    this.threadId,
    this.userId,
    this.id,
    this.msgCount,
    this.chatType,
    this.requestType,
    this.lastMsg,
    this.lastMsgUserId,
    this.lastMsgAt,
    this.peerLastReadMessageId,
    this.conversationId,
    this.deletedId,
    this.isDeleted,
    this.iAmBlocked,
    this.iBlocked,
  });

  /// Maps the server's neutral thread JSON (user1/user2 sides) to the current
  /// user's perspective, and seeds the user cache with the embedded summary.
  factory ChatThread.fromServerJson(Map<String, dynamic> json) {
    final int myId = SessionManager.instance.getUserID();
    final bool amUser1 = json['user1_id'] == myId;
    final int? otherId = amUser1 ? json['user2_id'] : json['user1_id'];
    final Map<String, dynamic>? otherSummary =
        (amUser1 ? json['user2'] : json['user1'])?.cast<String, dynamic>();

    final String status = json['status'] ?? 'approved';
    final bool iAmInitiator = json['initiator_id'] == myId;

    String? lastMsg = json['last_msg'];
    if (lastMsg != null && json['last_msg_user_id'] == myId) {
      lastMsg = 'You: $lastMsg';
    }

    final thread = ChatThread(
      threadId: json['id'],
      id: '${json['last_msg_at'] ?? json['id'] ?? 0}',
      userId: otherId,
      msgCount: amUser1 ? json['user1_unread_count'] : json['user2_unread_count'],
      // The receiver of a pending request sees it in the requests tab; the
      // initiator keeps a normal chat view.
      chatType: status == 'approved'
          ? ChatType.approved
          : (iAmInitiator ? ChatType.approved : ChatType.request),
      requestType: status == 'approved' ? 'accept' : null,
      lastMsg: lastMsg,
      lastMsgUserId: json['last_msg_user_id'],
      lastMsgAt: json['last_msg_at'],
      peerLastReadMessageId: amUser1
          ? json['user2_last_read_message_id']
          : json['user1_last_read_message_id'],
      conversationId: [myId, otherId].conversationId,
      isDeleted: false,
      deletedId: 0,
      iBlocked: json['i_blocked'],
      iAmBlocked: json['i_am_blocked'],
    );

    if (otherSummary != null) {
      final appUser = AppUser(
          userId: otherSummary['id'],
          fullname: otherSummary['fullname'],
          username: otherSummary['username'],
          profile: otherSummary['profile_photo'],
          isVerify: otherSummary['is_verify']);
      if (Get.isRegistered<AppUserCacheController>()) {
        Get.find<AppUserCacheController>().addAppUser(appUser);
      }
      thread._chatUser.value = appUser;
    }
    return thread;
  }

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> data = <String, dynamic>{};
    data['thread_id'] = threadId;
    data['user_id'] = userId;
    data['id'] = id;
    data['msg_count'] = msgCount;
    data['chat_type'] = chatType?.value;
    data['request_type'] = requestType;
    data['last_msg'] = lastMsg;
    data['last_msg_user_id'] = lastMsgUserId;
    data['last_msg_at'] = lastMsgAt;
    data['conversation_id'] = conversationId;
    data['i_am_blocked'] = iAmBlocked;
    data['i_blocked'] = iBlocked;
    return data;
  }

  // Reactive variable for chat user
  final Rx<AppUser?> _chatUser = Rx<AppUser?>(null);

  AppUser? get chatUser => _chatUser.value;

  set chatUser(AppUser? user) {
    if (user == null) return;
    _chatUser.value = user;
    if (Get.isRegistered<AppUserCacheController>()) {
      Get.find<AppUserCacheController>().addAppUser(user);
    }
  }

  /// Expose Rx version for reactive UI (`Obx`)
  Rx<AppUser?> get chatUserRx => _chatUser;

  /// Initialize and auto-sync with the user cache.
  void bindChatUser() {
    if (!Get.isRegistered<AppUserCacheController>()) return;
    final controller = Get.find<AppUserCacheController>();

    void updateUser() {
      final appUser = controller.users
          .firstWhereOrNull((element) => element.userId == userId);
      if (appUser == null) {
        UserService.instance
            .fetchUserDetails(
          userId: userId,
          onError: () => controller.deleteUser(userId),
        )
            .then((value) {
          if (value == null) {
            controller.deleteUser(userId);
          } else {
            controller.addUser(value);
          }
        });
        return;
      }
      _chatUser.value = appUser;
    }

    // React when users list changes
    ever(controller.users, (_) => updateUser());

    // Initial call
    updateUser();
  }
}

enum ChatType {
  request('request'),
  approved('approved');

  final String value;

  const ChatType(this.value);

  static ChatType fromString(String value) {
    return ChatType.values.firstWhereOrNull((e) => e.value == value) ??
        ChatType.approved;
  }
}

import 'package:shortzz/common/service/api/api_service.dart';
import 'package:shortzz/common/service/utils/params.dart';
import 'package:shortzz/common/service/utils/web_service.dart';
import 'package:shortzz/model/chat/chat_thread.dart';
import 'package:shortzz/model/chat/message_data.dart';
import 'package:shortzz/model/general/status_model.dart';

/// REST layer for MySQL chat (realtime deltas arrive via RealtimeService;
/// these endpoints are the source of truth and the polling fallback).
class ChatService {
  ChatService._();

  static final ChatService instance = ChatService._();

  Future<ThreadsPage> fetchThreads({int? lastMsgAt, int limit = 20}) async {
    return ApiService.instance.call(
        url: WebService.chat.fetchThreads,
        param: {Params.lastMsgAt: lastMsgAt, Params.limit: limit},
        fromJson: ThreadsPage.fromJson);
  }

  Future<UnreadCounts> fetchUnreadCounts() async {
    return ApiService.instance.call(
        url: WebService.chat.fetchUnreadCounts, fromJson: UnreadCounts.fromJson);
  }

  /// History mode ([lastMessageId] cursor, newest first) or polling mode
  /// ([afterMessageId], oldest first). Resolve by [threadId] or, when no
  /// thread exists yet, by [otherUserId] (returns an empty page then).
  Future<MessagesPage> fetchMessages(
      {int? threadId,
      int? otherUserId,
      int? lastMessageId,
      int? afterMessageId,
      int limit = 30}) async {
    return ApiService.instance.call(
        url: WebService.chat.fetchMessages,
        param: {
          Params.threadId: threadId,
          Params.otherUserId: otherUserId,
          Params.lastMessageId: lastMessageId,
          Params.afterMessageId: afterMessageId,
          Params.limit: limit,
        },
        fromJson: MessagesPage.fromJson);
  }

  Future<SendMessageResult> sendMessage(
      {int? threadId,
      int? receiverId,
      required MessageType type,
      String? textMessage,
      String? imageMessage,
      String? videoMessage,
      String? audioMessage,
      String? waveData,
      String? postMessage,
      String? storyReplyMessage}) async {
    return ApiService.instance.call(
        url: WebService.chat.sendMessage,
        param: {
          Params.threadId: threadId,
          Params.receiverId: receiverId,
          Params.messageType: type.value,
          Params.textMessage: textMessage,
          Params.imageMessage: imageMessage,
          Params.videoMessage: videoMessage,
          Params.audioMessage: audioMessage,
          Params.waveData: waveData,
          Params.postMessage: postMessage,
          Params.storyReplyMessage: storyReplyMessage,
        },
        fromJson: SendMessageResult.fromJson);
  }

  Future<StatusModel> markThreadRead({required int threadId}) async {
    return ApiService.instance.call(
        url: WebService.chat.markThreadRead,
        param: {Params.threadId: threadId},
        fromJson: StatusModel.fromJson);
  }

  Future<StatusModel> acceptChatRequest({required int threadId}) async {
    return ApiService.instance.call(
        url: WebService.chat.acceptChatRequest,
        param: {Params.threadId: threadId},
        fromJson: StatusModel.fromJson);
  }

  Future<StatusModel> rejectChatRequest({required int threadId}) async {
    return ApiService.instance.call(
        url: WebService.chat.rejectChatRequest,
        param: {Params.threadId: threadId},
        fromJson: StatusModel.fromJson);
  }

  Future<StatusModel> unsendMessage({required int messageId}) async {
    return ApiService.instance.call(
        url: WebService.chat.unsendMessage,
        param: {Params.messageId: messageId},
        fromJson: StatusModel.fromJson);
  }

  Future<StatusModel> deleteMessageForMe({required int messageId}) async {
    return ApiService.instance.call(
        url: WebService.chat.deleteMessageForMe,
        param: {Params.messageId: messageId},
        fromJson: StatusModel.fromJson);
  }

  Future<StatusModel> deleteThread({required int threadId}) async {
    return ApiService.instance.call(
        url: WebService.chat.deleteThread,
        param: {Params.threadId: threadId},
        fromJson: StatusModel.fromJson);
  }
}

class ThreadsPage {
  bool? status;
  String? message;
  List<ChatThread> threads = [];
  int totalUnreadCount = 0;

  ThreadsPage.fromJson(Map<String, dynamic> json) {
    status = json['status'];
    message = json['message'];
    final data = json['data'];
    if (data is Map<String, dynamic>) {
      totalUnreadCount = data['total_unread_count'] ?? 0;
      for (final item in (data['threads'] as List? ?? [])) {
        threads.add(ChatThread.fromServerJson(item.cast<String, dynamic>()));
      }
    }
  }
}

class UnreadCounts {
  bool? status;
  int unreadThreadCount = 0;
  int requestUnreadThreadCount = 0;

  UnreadCounts.fromJson(Map<String, dynamic> json) {
    status = json['status'];
    final data = json['data'];
    if (data is Map<String, dynamic>) {
      unreadThreadCount = data['unread_thread_count'] ?? 0;
      requestUnreadThreadCount = data['request_unread_thread_count'] ?? 0;
    }
  }
}

class MessagesPage {
  bool? status;
  String? message;
  List<MessageData> messages = [];
  ChatThread? thread;

  MessagesPage.fromJson(Map<String, dynamic> json) {
    status = json['status'];
    message = json['message'];
    final data = json['data'];
    if (data is Map<String, dynamic>) {
      for (final item in (data['messages'] as List? ?? [])) {
        messages.add(MessageData.fromServerJson(item.cast<String, dynamic>()));
      }
      if (data['thread'] is Map) {
        thread = ChatThread.fromServerJson(
            (data['thread'] as Map).cast<String, dynamic>());
      }
    }
  }
}

class SendMessageResult {
  bool? status;
  String? message;
  MessageData? sentMessage;
  ChatThread? thread;

  SendMessageResult.fromJson(Map<String, dynamic> json) {
    status = json['status'];
    message = json['message'];
    final data = json['data'];
    if (data is Map<String, dynamic>) {
      if (data['message'] is Map) {
        sentMessage = MessageData.fromServerJson(
            (data['message'] as Map).cast<String, dynamic>());
      }
      if (data['thread'] is Map) {
        thread = ChatThread.fromServerJson(
            (data['thread'] as Map).cast<String, dynamic>());
      }
    }
  }
}

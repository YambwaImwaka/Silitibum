import 'package:get/get.dart';
import 'package:shortzz/common/controller/app_user_cache_controller.dart';
import 'package:shortzz/model/livestream/app_user.dart';

/// A chat message (MySQL-backed). [id] is the server auto-increment id;
/// optimistic local messages use a negative temp id until the server row
/// replaces them. [createdAt] is epoch milliseconds.
class MessageData {
  int? id;
  int? threadId;
  int? userId; // sender id
  MessageType? messageType;
  String? textMessage;
  String? imageMessage;
  String? videoMessage;
  String? audioMessage;
  String? postMessage;
  String? storyReplyMessage;
  String? conversationId;
  bool? isUnsent;
  int? createdAt;
  String? waveData;

  MessageData(
      {this.userId,
      this.id,
      this.threadId,
      this.messageType,
      this.textMessage,
      this.imageMessage,
      this.videoMessage,
      this.audioMessage,
      this.postMessage,
      this.storyReplyMessage,
      this.conversationId,
      this.isUnsent,
      this.createdAt,
      this.waveData});

  MessageData.fromServerJson(Map<String, dynamic> json) {
    id = json['id'];
    threadId = json['thread_id'];
    userId = json['sender_id'];
    messageType = MessageType.fromString(json['message_type'] ?? 'text');
    textMessage = json['text_message'];
    imageMessage = json['image_message'];
    videoMessage = json['video_message'];
    audioMessage = json['audio_message'];
    postMessage = json['post_message'];
    storyReplyMessage = json['story_reply_message'];
    waveData = json['wave_data'];
    isUnsent = json['is_unsent'] == 1 || json['is_unsent'] == true;
    createdAt = json['created_at'];
  }

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> data = <String, dynamic>{};
    data['id'] = id;
    data['thread_id'] = threadId;
    data['sender_id'] = userId;
    data['message_type'] = messageType?.value;
    data['text_message'] = textMessage;
    data['image_message'] = imageMessage;
    data['video_message'] = videoMessage;
    data['audio_message'] = audioMessage;
    data['post_message'] = postMessage;
    data['story_reply_message'] = storyReplyMessage;
    data['wave_data'] = waveData;
    data['is_unsent'] = isUnsent;
    data['created_at'] = createdAt;
    return data;
  }

  AppUser? get chatUser {
    if (!Get.isRegistered<AppUserCacheController>()) return null;
    final controller = Get.find<AppUserCacheController>();
    return controller.users
        .firstWhereOrNull((element) => element.userId == userId);
  }
}

enum MessageType {
  text('text'),
  image('image'),
  video('video'),
  post('post'),
  gift('gift'),
  audio('audio'),
  gif('gif'),
  storyReply('story_reply');

  final String value;

  const MessageType(this.value);

  static MessageType fromString(String value) {
    return MessageType.values.firstWhereOrNull(
          (e) => e.value == value,
        ) ??
        MessageType.text;
  }
}

enum StoryReplyType {
  text('text'),
  gift('gift');

  final String value;

  const StoryReplyType(this.value);

  static StoryReplyType fromString(String value) {
    return StoryReplyType.values.firstWhereOrNull(
          (e) => e.value == value,
        ) ??
        StoryReplyType.text;
  }
}

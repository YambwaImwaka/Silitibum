import 'dart:convert';
import 'dart:io';

import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:get/get.dart';
import 'package:shortzz/common/controller/base_controller.dart';
import 'package:shortzz/common/manager/logger.dart';
import 'package:shortzz/common/manager/session_manager.dart' show SessionManager;
import 'package:shortzz/common/service/api/chat_service.dart';
import 'package:shortzz/common/service/api/livestream_service.dart';
import 'package:shortzz/common/service/api/notification_service.dart';
import 'package:shortzz/common/service/api/post_service.dart';
import 'package:shortzz/common/service/api/user_service.dart';
import 'package:shortzz/common/service/navigation/navigate_with_controller.dart';
import 'package:shortzz/languages/dynamic_translations.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/model/livestream/livestream.dart';
import 'package:shortzz/model/post_story/post_model.dart';
import 'package:shortzz/screen/chat_screen/chat_screen.dart';
import 'package:shortzz/screen/chat_screen/chat_screen_controller.dart';
import 'package:shortzz/screen/dashboard_screen/dashboard_screen_controller.dart';
import 'package:shortzz/screen/earnings_screen/earnings_screen.dart';
import 'package:shortzz/screen/live_stream/live_feed_screen/live_feed_screen_controller.dart';
import 'package:shortzz/screen/live_stream/livestream_screen/host/livestream_host_screen.dart';
import 'package:shortzz/screen/live_stream/livestream_screen/livestream_screen_controller.dart';
import 'package:shortzz/screen/notification_screen/notification_screen.dart';
import 'package:shortzz/screen/payout_account_screen/payout_account_screen.dart';
import 'package:shortzz/screen/post_screen/single_post_screen.dart';
import 'package:shortzz/screen/reels_screen/reels_screen.dart';
import 'package:shortzz/screen/reels_screen/widget/reel_page_type.dart';
import 'package:shortzz/screen/withdrawals_screen/withdrawals_screen.dart';
import 'package:shortzz/utilities/const_res.dart';

@pragma('vm:entry-point')
void notificationTapBackground(NotificationResponse notificationResponse) {
  Loggers.info('NOTIFICATION TAP ON BACKGROUND');
  notificationResponse.data;
  if (notificationResponse.payload != null) {
    FirebaseNotificationManager.instance.handleNotification(notificationResponse.payload!);
  }
}

class FirebaseNotificationManager {
  FirebaseNotificationManager._() {
    init();
  }

  static final instance = FirebaseNotificationManager._();

  FirebaseMessaging firebaseMessaging = FirebaseMessaging.instance;
  final FlutterLocalNotificationsPlugin flutterLocalNotificationsPlugin =
      FlutterLocalNotificationsPlugin();
  RxString notificationPayload = ''.obs;
  AndroidNotificationChannel channel = const AndroidNotificationChannel(
      'shortzz', // id
      'Shortzz', // title
      playSound: true,
      enableLights: true,
      enableVibration: true,
      showBadge: false,
      importance: Importance.max);

  String? notificationId;

  void init() async {
    if (Platform.isAndroid) {
      await flutterLocalNotificationsPlugin
          .resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>()
          ?.requestNotificationsPermission();
    } else {
      await flutterLocalNotificationsPlugin
          .resolvePlatformSpecificImplementation<IOSFlutterLocalNotificationsPlugin>()
          ?.requestPermissions(alert: true, sound: true);
      await firebaseMessaging.requestPermission(alert: true, badge: false, sound: true);
    }

    subscribeToTopic();

    var initializationSettingsAndroid = const AndroidInitializationSettings('@mipmap/ic_launcher');

    var initializationSettingsIOS = const DarwinInitializationSettings(
        defaultPresentAlert: true, defaultPresentSound: true, defaultPresentBadge: false);

    var initializationSettings = InitializationSettings(
        android: initializationSettingsAndroid, iOS: initializationSettingsIOS);

    // Handling notification taps
    flutterLocalNotificationsPlugin.initialize(
        settings: initializationSettings,
        onDidReceiveNotificationResponse: (NotificationResponse response) {
          Loggers.info('onDidReceiveNotificationResponse ${response.payload}');
          String? payload = response.payload;
          if (payload != null) {
            notificationPayload.value = payload;
          }
        },
        onDidReceiveBackgroundNotificationResponse: notificationTapBackground);

    FirebaseMessaging.onMessage.listen((RemoteMessage message) {
      // If Notification has gone twice
      if (notificationId == message.messageId) return;
      notificationId = message.messageId;

      String data = message.data['notification_data'] ?? '';

      if (message.data['type'] == NotificationType.chat.type) {
        // Suppress the banner while that thread's chat screen is open.
        try {
          final int? threadId = jsonDecode(data)['thread_id'];
          if (threadId != null &&
              threadId == ChatScreenController.activeThreadId) {
            return;
          }
        } catch (e) {
          Loggers.error('chat notification payload parse failed: $e');
        }
      } else {
        SessionManager.instance.setNotifyCount(1);
      }
      showNotification(message);
    });

    FirebaseMessaging.onMessageOpenedApp.listen((RemoteMessage message) {
      Loggers.info('User tapped the notification: ${message.data}');
      Loggers.info('FirebaseMessaging.onMessageOpenedApp');
      if (message.data.isNotEmpty) {
        handleNotification(jsonEncode(message.toMap()));
      }
    });

    await flutterLocalNotificationsPlugin
        .resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>()
        ?.createNotificationChannel(channel);
  }

  void unsubscribeToTopic({String? topic}) async {
    Loggers.success(
        '🔔 Topic UnSubscribe : ${topic ?? notificationTopic}_${Platform.isAndroid ? 'android' : 'ios'}');
    await firebaseMessaging.unsubscribeFromTopic(
        '${topic ?? notificationTopic}_${Platform.isAndroid ? 'android' : 'ios'}');
    if (kDebugMode) {
      await firebaseMessaging.unsubscribeFromTopic(
          'test_${topic ?? notificationTopic}_${Platform.isAndroid ? 'android' : 'ios'}');
    }
  }

  Future<void> subscribeToTopic({String? topic}) async {
    Loggers.success(
        '🔔 Topic Subscribe : ${topic ?? notificationTopic}_${Platform.isAndroid ? 'android' : 'ios'}');
    await firebaseMessaging.subscribeToTopic(
        '${topic ?? notificationTopic}_${Platform.isAndroid ? 'android' : 'ios'}');

    if (kDebugMode) {
      await firebaseMessaging.subscribeToTopic(
          'test_${topic ?? notificationTopic}_${Platform.isAndroid ? 'android' : 'ios'}');
    }
  }

  void showNotification(RemoteMessage message) {
    Loggers.info('SHOW MESSAGE : ${message.toMap()}');
    int notificationId = DateTime.now().millisecondsSinceEpoch.remainder(100000);

    flutterLocalNotificationsPlugin.show(
        id: notificationId,
        title: (message.data['title']) ?? message.notification?.title,
        body: (message.data['body'] as String?) ?? message.notification?.body,
        notificationDetails: NotificationDetails(
            iOS: const DarwinNotificationDetails(
                presentSound: true, presentAlert: true, presentBadge: false),
            android: AndroidNotificationDetails(channel.id, channel.name)),
        payload: jsonEncode(message.toMap()));
  }

  /// Opens what a tapped push is about.
  Future<void> handleNotification(String payload) async {
    final RemoteMessage message = RemoteMessage.fromMap(jsonDecode(payload));
    final String? dataType = message.data['type']?.toString();
    final String dataString = message.data['notification_data']?.toString() ?? '';
    Loggers.info('DATA TYPE : $dataType');
    Loggers.info('DATA STRING : $dataString');
    if (dataType == null) return;
    final controller = Get.put(DashboardScreenController());
    switch (dataType) {
      case 'chat':
        if (dataString.isEmpty) return;
        Future.delayed(const Duration(milliseconds: 500),
            () => _handleChatNotification(dataString));
        break;
      case 'post':
        if (dataString.isEmpty) return;
        await _handlePostNotification(dataString);
        break;
      case 'user':
        if (dataString.isEmpty) return;
        await _handleUserNotification(dataString);
        break;
      case 'live_stream':
        if (dataString.isEmpty) return;
        controller.selectedPageIndex.value = DashboardScreenController.liveTab;
        await _handleLivestreamNotification(dataString);
        break;
      case 'verification':
        // The blue tick: given, renewed or about to lapse.
        controller.selectedPageIndex.value =
            DashboardScreenController.profileTab;
        break;
      case 'withdrawal':
        Get.to(() => const WithdrawalsScreen());
        break;
      case 'payout_account':
        Get.to(() => const PayoutAccountScreen());
        break;
      case 'wallet':
        Get.to(() => const EarningsScreen());
        break;
      default:
        // Activity pushes sent before the server translated them carry the
        // bare type number and nothing to open; the list shows what they
        // were about.
        if (int.tryParse(dataType) != null) {
          Get.to(() => const NotificationScreen());
          return;
        }
        Loggers.warning('Unknown notification type: $dataType');
    }
  }

  Future<void> _handleChatNotification(String data) async {
    try {
      // Payload carries only ids; resolve the thread over REST before
      // opening the screen.
      final int? threadId = jsonDecode(data)['thread_id'];
      if (threadId == null) return;
      final page = await ChatService.instance.fetchMessages(
          threadId: threadId, limit: 1);
      final conversationUser = page.thread;
      if (conversationUser == null) return;
      await Get.to(() => ChatScreen(conversationUser: conversationUser));
    } catch (e) {
      Loggers.error('Failed to handle chat notification: $e');
    }
  }

  Future<void> _handlePostNotification(String data) async {
    try {
      NotificationInfo notificationInfo = NotificationInfo.fromJson(jsonDecode(data));
      final int postId = notificationInfo.id ?? -1;
      final int? commentId = notificationInfo.commentId;
      final int? replyId = notificationInfo.replyCommentId;
      final result = await PostService.instance
          .fetchPostById(postId: postId, commentId: commentId, replyId: replyId);

      if (result.status == true && result.data != null) {
        final Post? post = result.data?.post;
        if (post == null) return;

        if (post.postType == PostType.reel) {
          Get.to(() => ReelsScreen(
                reels: [post].obs,
                position: 0,
                postByIdData: result.data,
                pageType: ReelPageType.notification,
              ));
        } else if ([PostType.text, PostType.image, PostType.video].contains(post.postType)) {
          await Get.to(() =>
              SinglePostScreen(post: post, postByIdData: result.data, isFromNotification: true));
        }
      }
    } catch (e) {
      Loggers.error('Failed to handle post notification: $e');
    }
  }

  Future<void> _handleUserNotification(String data) async {
    try {
      final map = jsonDecode(data);
      final int id = map['id'];
      final user = await UserService.instance.fetchUserDetails(userId: id);

      if (user != null) {
        Loggers.success('Navigating to user: ${user.id}');
        NavigationService.shared.openProfileScreen(user);
      }
    } catch (e) {
      Loggers.error('Failed to handle user notification: $e');
    }
  }

  Future<String?> getNotificationToken() async {
    try {
      // getToken() has no internal bound and is a known hang point (stalled
      // APNs/Play-Services registration) — without this timeout a stuck call
      // here freezes the login/signup spinner forever, since push is optional
      // and the caller must always get an answer.
      String? token = await FirebaseMessaging.instance
          .getToken()
          .timeout(const Duration(seconds: 5), onTimeout: () => null);
      Loggers.info('DeviceToken $token');
      return token;
    } catch (e) {
      Loggers.error('DeviceToken Exception $e');
      return null;
    }
  }

  Future<void> sendLocalisationNotification(
    String key, {
    Map<String, String> keyParams = const {},
    String? deviceToken = '',
    int? deviceType = 0,
    String? languageCode = 'en',
    required NotificationInfo body,
    required NotificationType type,
  }) async {
    // Early return if no device token provided
    if ((deviceToken ?? '').isEmpty) {
      Loggers.error('Device Token Empty - Notification not sent for key: $key');
      return;
    }

    // Get user data once
    final user = SessionManager.instance.getUser();
    final title = user?.fullname ?? '';

    // Get translations efficiently
    final translations = Get.find<DynamicTranslations>();
    final languageData = translations.keys[languageCode] ?? {};

    // Get description with fallback
    var description = languageData[key] ?? key;

    keyParams.forEach((key, value) {
      description = description.replaceAll('@$key', value);
    });

    // Log relevant information
    Loggers.info('''
      [Notification Details]
      Language: $languageCode
      Key: $key
      Description: $description
      Recipient: ${user?.id ?? 'Unknown'}
      Device Type: $deviceType
      Device Token: $deviceToken
    ''');

    // Send notification
    await NotificationService.instance.pushNotification(
        title: title,
        body: description,
        data: body.toJson(),
        deviceType: deviceType,
        token: deviceToken,
        type: type);
  }

  Future<void> _handleLivestreamNotification(String dataString) async {
    final incomingStream = Livestream.fromJson(jsonDecode(dataString));

    // Confirm the stream is still live before routing into it.
    final streams = await LivestreamService.instance.fetchLivestreams();
    final stream = streams
        .firstWhereOrNull((element) => element.roomID == incomingStream.roomID);

    if (stream == null) {
      BaseController.share.showSnackBar(LKey.livestreamHasEnded.tr);
      return;
    }

    // Already in a room of their own (hosting): leaving it would end it.
    if (Get.isRegistered<LivestreamScreenController>()) return;

    final myUser = SessionManager.instance.getUser();

    if (stream.hostId == myUser?.id) {
      Get.to(() => LivestreamHostScreen(isHost: true, livestream: stream));
    } else {
      // Lives are watched in the Live tab (selected above): back to it, at
      // this room. A room screen opened over the tab would have joined the
      // same room a second time.
      Get.until((route) => route.isFirst);
      LiveFeedScreenController.open(stream);
    }
  }
}

enum NotificationType {
  chat('chat'),
  post('post'),
  user('user'),
  liveStream('live_stream'),
  other('other');

  final String type;

  const NotificationType(this.type);
}

class NotificationInfo {
  int? id;
  int? commentId;
  int? replyCommentId;

  NotificationInfo({
    this.id,
    this.commentId,
    this.replyCommentId,
  });

  factory NotificationInfo.fromJson(Map<String, dynamic> json) => NotificationInfo(
        id: json["id"],
        commentId: json["comment_id"],
        replyCommentId: json["reply_comment_id"],
      );

  Map<String, dynamic> toJson() => {
        "id": id,
        "comment_id": commentId,
        "reply_comment_id": replyCommentId,
      };
}

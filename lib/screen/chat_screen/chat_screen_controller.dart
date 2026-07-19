import 'dart:async';
import 'dart:convert';

import 'package:audio_waveforms/audio_waveforms.dart';
import 'package:flutter/material.dart';
import 'package:flutter_cache_manager/flutter_cache_manager.dart';
import 'package:get/get.dart';
import 'package:image_picker/image_picker.dart';
import 'package:permission_handler/permission_handler.dart';
import 'package:shortzz/common/enum/chat_enum.dart';
import 'package:shortzz/common/functions/auth_gate.dart';
import 'package:shortzz/common/extensions/string_extension.dart';
import 'package:shortzz/common/functions/media_picker_helper.dart';
import 'package:shortzz/common/manager/logger.dart';
import 'package:shortzz/common/manager/session_manager.dart';
import 'package:shortzz/common/service/api/chat_service.dart';
import 'package:shortzz/common/service/api/common_service.dart';
import 'package:shortzz/common/service/api/post_service.dart';
import 'package:shortzz/common/service/api/user_service.dart';
import 'package:shortzz/common/service/realtime/realtime_service.dart';
import 'package:shortzz/common/widget/confirmation_dialog.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/model/chat/chat_thread.dart';
import 'package:shortzz/model/chat/message_data.dart';
import 'package:shortzz/model/general/settings_model.dart';
import 'package:shortzz/model/general/status_model.dart';
import 'package:shortzz/model/livestream/app_user.dart';
import 'package:shortzz/model/post_story/post_model.dart';
import 'package:shortzz/model/post_story/story/story_model.dart';
import 'package:shortzz/model/user_model/user_model.dart';
import 'package:shortzz/screen/blocked_user_screen/block_user_controller.dart';
import 'package:shortzz/screen/chat_screen/message_type_widget/chat_audio_message.dart';
import 'package:shortzz/screen/chat_screen/widget/select_media_sheet.dart';
import 'package:shortzz/screen/chat_screen/widget/send_media_sheet.dart';
import 'package:shortzz/screen/dashboard_screen/dashboard_screen_controller.dart';
import 'package:shortzz/screen/gif_sheet/gif_sheet.dart';
import 'package:shortzz/screen/gift_sheet/send_gift_sheet_controller.dart';
import 'package:shortzz/screen/post_screen/post_screen_controller.dart';
import 'package:shortzz/screen/post_screen/single_post_screen.dart';
import 'package:shortzz/screen/reels_screen/reel/reel_page_controller.dart';
import 'package:shortzz/screen/reels_screen/reels_screen.dart';
import 'package:shortzz/screen/reels_screen/widget/reel_page_type.dart';
import 'package:shortzz/screen/report_sheet/report_sheet.dart';
import 'package:shortzz/screen/story_view_screen/story_view_screen.dart';
import 'package:shortzz/utilities/app_res.dart';
import 'package:shortzz/utilities/color_res.dart';
import 'package:shortzz/utilities/style_res.dart';

/// 1:1 chat over the MySQL backend: REST for history and sends, realtime
/// events for live updates, and a short-interval polling fallback whenever
/// the WebSocket is down (replaces the Firestore implementation).
class ChatScreenController extends BlockUserController with GetTickerProviderStateMixin {
  List<UserRequestAction> requestType = UserRequestAction.values;
  User? myUser = SessionManager.instance.getUser();
  final Setting? setting = SessionManager.instance.getSettings();
  User? otherUser;

  RxBool isTextEmpty = true.obs;
  RxBool hasMore = true.obs;
  RxBool isExpanded = false.obs;
  bool isPostAPiCalling = false;

  TextEditingController textController = TextEditingController();
  TextEditingController mediaTextController = TextEditingController();
  Rx<ChatThread> conversationUser;

  late AnimationController audioAnimationController;
  Animation<double>? audioWidthAnimation;

  MessageType chatType = MessageType.text;

  RxList<MessageData> chatList = <MessageData>[].obs;

  StreamSubscription<PlayerState>? playerControllerListen;
  StreamSubscription<RealtimeEvent>? _realtimeSub;
  Timer? _pollTimer;

  RecorderController recorderController = RecorderController();
  PlayerController playerController = PlayerController();
  Rx<PlayerValue> playerValue = PlayerValue(state: PlayerState.stopped, id: 0).obs;

  ChatScreenController(this.conversationUser);

  /// Set while a chat screen is open — the FCM foreground handler suppresses
  /// notifications for the visible thread.
  static String chatId = '';
  static int? activeThreadId;

  int? get threadId => conversationUser.value.threadId;

  /// Highest server-assigned message id we hold (optimistic temp ids are
  /// epoch-ms and must not feed the polling cursor).
  int _maxServerMessageId = 0;
  String? _subscribedChannel;

  @override
  void onInit() {
    super.onInit();
    chatId = conversationUser.value.conversationId ?? 'No CONVERSATION';
    activeThreadId = conversationUser.value.threadId;
  }

  @override
  void onReady() {
    super.onReady();
    _init();
    _fetchInitialMessages();
  }

  @override
  void onClose() {
    super.onClose();
    chatId = '';
    activeThreadId = null;
    _realtimeSub?.cancel();
    _pollTimer?.cancel();
    if (_subscribedChannel != null) {
      RealtimeService.instance.unsubscribe(_subscribedChannel!);
    }

    playerControllerListen?.cancel();
    audioAnimationController.dispose();
    recorderController.dispose();
    playerController.dispose();
    textController.dispose();
    mediaTextController.dispose();

    _markAsRead();
  }

  _init() {
    _initAudioAnimationController();
    _initializePlayerStateListener();
    _fetchOtherUser();
  }

  _initAudioAnimationController() {
    audioAnimationController =
        AnimationController(vsync: this, duration: const Duration(milliseconds: 400));

    final double maxWidth = Get.width - 30;

    audioWidthAnimation = Tween<double>(
      begin: 0, // Start with 0 width
      end: maxWidth, // Expand to full width
    ).animate(CurvedAnimation(parent: audioAnimationController, curve: Curves.easeInOut));
  }

  _initializePlayerStateListener() {
    playerControllerListen = playerController.onPlayerStateChanged.listen((event) {
      playerValue.update((val) => val?.state = event);
      Loggers.success('Player State: $event');
    });
  }

  _fetchOtherUser() async {
    int userId = conversationUser.value.userId ?? -1;
    if (userId != -1) {
      otherUser = await UserService.instance.fetchUserDetails(userId: userId);
    }
  }

  // ---------------------------------------------------------------------
  // FETCH + REALTIME + POLLING
  // ---------------------------------------------------------------------

  Future<void> _fetchInitialMessages() async {
    if (!SessionManager.instance.isLogin()) return;
    try {
      final page = await ChatService.instance.fetchMessages(
          threadId: threadId,
          otherUserId: threadId == null ? conversationUser.value.userId : null,
          limit: AppRes.chatPaginationLimit);
      if (page.thread != null) {
        _applyThread(page.thread!);
      }
      _mergeMessages(page.messages);
      hasMore.value = page.messages.length >= AppRes.chatPaginationLimit;
      _markAsRead();
    } catch (e) {
      Loggers.error('fetchInitialMessages failed: $e');
    }
    _startRealtime();
  }

  Future<void> fetchMoreChatList() async {
    if (!hasMore.value || isLoading.value || threadId == null) return;
    isLoading.value = true;
    try {
      final serverIds = chatList.map((e) => e.id ?? 0).where(_isServerId);
      final oldest = serverIds.isEmpty
          ? null
          : serverIds.reduce((a, b) => a < b ? a : b);
      final page = await ChatService.instance.fetchMessages(
          threadId: threadId,
          lastMessageId: oldest,
          limit: AppRes.chatPaginationLimit);
      if (page.messages.isEmpty) {
        hasMore.value = false;
      } else {
        _mergeMessages(page.messages);
      }
    } catch (e) {
      Loggers.error('fetchMoreChatList failed: $e');
    } finally {
      isLoading.value = false;
    }
  }

  // Server ids are small sequential values; optimistic temp ids are epoch ms.
  bool _isServerId(int id) => id > 0 && id < 1000000000000;

  void _mergeMessages(List<MessageData> messages) {
    for (final message in messages) {
      chatList.removeWhere((element) => element.id == message.id);
      chatList.add(message);
      if (_isServerId(message.id ?? 0) && (message.id ?? 0) > _maxServerMessageId) {
        _maxServerMessageId = message.id!;
      }
    }
    chatList.sort((a, b) => (b.id ?? 0).compareTo(a.id ?? 0));
  }

  void _startRealtime() {
    final tId = threadId;
    if (tId != null && _subscribedChannel == null) {
      RealtimeService.instance
          .subscribePrivate('chat.thread.$tId', RealtimeService.chatEvents);
      _subscribedChannel = 'private-chat.thread.$tId';
      activeThreadId = tId;
    }

    _realtimeSub ??= RealtimeService.instance.events.listen(_onRealtimeEvent);

    // Poll every few seconds while the socket is down (also covers the
    // disabled-realtime configuration).
    _pollTimer ??= Timer.periodic(const Duration(seconds: 4), (_) {
      if (!RealtimeService.instance.isConnected) _pollNewMessages();
    });
  }

  void _onRealtimeEvent(RealtimeEvent event) {
    final threadJson = event.data['thread'];
    final int? eventThreadId =
        threadJson is Map ? threadJson['id'] : event.data['thread_id'];
    if (threadId == null || eventThreadId != threadId) return;

    switch (event.name) {
      case 'message.sent':
        if (event.data['message'] is Map) {
          final message = MessageData.fromServerJson(
              (event.data['message'] as Map).cast<String, dynamic>());
          // Own sends already landed via the REST response.
          if (message.userId != myUser?.id) {
            _mergeMessages([message]);
            _markAsRead();
          }
        }
        if (threadJson is Map) {
          _applyThread(
              ChatThread.fromServerJson(threadJson.cast<String, dynamic>()));
        }
        break;
      case 'message.unsent':
        final int? messageId = event.data['message_id'];
        final index = chatList.indexWhere((element) => element.id == messageId);
        if (index != -1) {
          final message = chatList[index];
          message.isUnsent = true;
          message.textMessage = null;
          message.imageMessage = null;
          message.videoMessage = null;
          message.audioMessage = null;
          message.postMessage = null;
          message.storyReplyMessage = null;
          chatList[index] = message;
        }
        break;
      case 'thread.updated':
        if (threadJson is Map) {
          _applyThread(
              ChatThread.fromServerJson(threadJson.cast<String, dynamic>()));
        }
        break;
      case 'thread.deleted':
        // The other side rejected the request — leave the dead screen.
        if (Get.isRegistered<ChatScreenController>(
            tag: conversationUser.value.conversationId)) {
          Get.back();
        }
        break;
    }
  }

  Future<void> _pollNewMessages() async {
    final tId = threadId;
    if (tId == null || !SessionManager.instance.isLogin()) return;
    try {
      final page = await ChatService.instance
          .fetchMessages(threadId: tId, afterMessageId: _maxServerMessageId);
      if (page.messages.isNotEmpty) {
        _mergeMessages(page.messages);
        _markAsRead();
      }
      if (page.thread != null) _applyThread(page.thread!);
    } catch (e) {
      Loggers.error('chat poll failed: $e');
    }
  }

  /// Applies a fresh thread payload while preserving the resolved chat user.
  void _applyThread(ChatThread thread) {
    final AppUser? existingUser = conversationUser.value.chatUser;
    if (thread.chatUser == null && existingUser != null) {
      thread.chatUser = existingUser;
    }
    conversationUser.value = thread;
    activeThreadId = thread.threadId;
  }

  // ---------------------------------------------------------------------
  // SEND
  // ---------------------------------------------------------------------

  void onSendTextMessage() async {
    if (!AuthGate.check()) return;
    String text = textController.text.trim();
    if (text.isEmpty) return;
    textController.clear();
    isTextEmpty.value = true;
    if (conversationUser.value.iAmBlocked ?? false) {
      return showSnackBar(
          'You cannot message ${conversationUser.value.chatUser?.username} because you are blocked by them.');
    }
    sendChatMessage(type: MessageType.text, textMessage: text);
  }

  Future<void> sendChatMessage(
      {required MessageType type,
      String? textMessage,
      String? imageMessage,
      String? videoMessage,
      String? audioMessage,
      String? postMessage,
      String? storyReplyMessage,
      List<double>? waveData}) async {
    final int tempId = DateTime.now().millisecondsSinceEpoch;

    // Optimistic append; the server row replaces it on success.
    final optimistic = MessageData(
      id: tempId,
      threadId: threadId,
      userId: myUser?.id,
      conversationId: conversationUser.value.conversationId,
      messageType: type,
      textMessage: textMessage,
      imageMessage: imageMessage,
      videoMessage: videoMessage,
      audioMessage: audioMessage,
      postMessage: postMessage,
      storyReplyMessage: storyReplyMessage,
      waveData: waveData?.join(','),
      isUnsent: false,
      createdAt: tempId,
    );
    chatList.insert(0, optimistic);

    try {
      final result = await ChatService.instance.sendMessage(
          threadId: threadId,
          receiverId: threadId == null ? conversationUser.value.userId : null,
          type: type,
          textMessage: textMessage,
          imageMessage: imageMessage,
          videoMessage: videoMessage,
          audioMessage: audioMessage,
          waveData: waveData?.join(','),
          postMessage: postMessage,
          storyReplyMessage: storyReplyMessage);

      chatList.removeWhere((element) => element.id == tempId);
      if (result.status == true) {
        if (result.sentMessage != null) _mergeMessages([result.sentMessage!]);
        if (result.thread != null) {
          _applyThread(result.thread!);
          _startRealtime(); // first message may have just created the thread
        }
      } else {
        showSnackBar(result.message == 'blocked'
            ? LKey.somethingWentWrong.tr
            : result.message);
      }
    } catch (e) {
      chatList.removeWhere((element) => element.id == tempId);
      Loggers.error('sendChatMessage failed: $e');
      showSnackBar(LKey.somethingWentWrong.tr);
    }
  }

  void onTextFieldChanged(String value) {
    if (value.trim().isNotEmpty) {
      isTextEmpty.value = false;
    } else {
      isTextEmpty.value = true;
    }
  }

  // ---------------------------------------------------------------------
  // ACTIONS (gift / audio / sticker / media)
  // ---------------------------------------------------------------------

  onChatActionTap(ChatAction action) {
    if (conversationUser.value.iAmBlocked ?? false) {
      return showSnackBar(
          'You cannot message ${conversationUser.value.chatUser?.username} because you are blocked by them.');
    }
    FocusManager.instance.primaryFocus?.unfocus();
    switch (action) {
      case ChatAction.gift:
        pickGift();
        break;
      case ChatAction.audio:
        _pickAudio();
        break;
      case ChatAction.sticker:
        pickSticker();
        break;
      case ChatAction.media:
        pickAndSendMedia();
        break;
    }
  }

  void onCameraTap() {
    if (conversationUser.value.iAmBlocked ?? false) {
      return showSnackBar(
          'You cannot message ${conversationUser.value.chatUser?.username} because you are blocked by them.');
    }
    FocusManager.instance.primaryFocus?.unfocus();
    Get.bottomSheet(SelectMediaSheet(
      onSelectMedia: (mediaFile) {
        Get.back();
        _showSendMediaSheet(mediaFile);
      },
    ), isScrollControlled: true);
  }

  void pickGift() {
    int? userId = conversationUser.value.chatUser?.userId;

    GiftManager.openGiftSheet(
        userId: userId ?? -1,
        onCompletion: (giftManager) {
          sendChatMessage(
              type: MessageType.gift,
              textMessage: giftManager.gift.coinPrice.toString(),
              imageMessage: giftManager.gift.image);
        });
  }

  void pickSticker() {
    Get.bottomSheet<String?>(const GifSheet(), isScrollControlled: true).then((value) {
      if (value != null) {
        sendChatMessage(type: MessageType.gif, imageMessage: value);
      }
    });
  }

  void pickAndSendMedia() async {
    MediaFile? mediaFile = await MediaPickerHelper.shared.pickMedia();
    if (mediaFile == null) return;
    mediaTextController.clear();
    _showSendMediaSheet(mediaFile);
  }

  void _showSendMediaSheet(MediaFile mediaFile) {
    Get.bottomSheet(
      SendMediaSheet(
          controller: this,
          image: mediaFile.thumbNail.path,
          onSendBtnClick: () {
            Get.back();
            _uploadAndSendMessage(mediaFile);
          }),
      isScrollControlled: true,
    );
  }

  Future<void> _uploadAndSendMessage(MediaFile mediaFile) async {
    showLoader();

    String filePath = await _uploadFile(mediaFile.file);

    String thumbnailPath =
        mediaFile.type == MediaType.video ? await _uploadFile(mediaFile.thumbNail) : '';
    stopLoader();
    bool isImageMessage = mediaFile.type == MediaType.image;
    if (filePath == '') {
      return Loggers.error('Filepath Not Found Please try Again');
    }
    if (!isImageMessage && thumbnailPath == '') {
      return Loggers.error('ThumbnailPath Not Found Please try Again');
    }

    sendChatMessage(
      type: isImageMessage ? MessageType.image : MessageType.video,
      imageMessage: isImageMessage ? filePath : thumbnailPath,
      videoMessage: !isImageMessage ? filePath : thumbnailPath,
      textMessage: mediaTextController.text.trim(),
    );
  }

  Future<String> _uploadFile(XFile file) async {
    return (await CommonService.instance.uploadFileGivePath(file)).data ?? '';
  }

  // ---------------------------------------------------------------------
  // AUDIO RECORD / PLAYBACK (unchanged mechanics)
  // ---------------------------------------------------------------------

  void toggleAnimation() {
    if (isExpanded.value) {
      audioAnimationController.reverse();
    } else {
      audioAnimationController.forward();
    }
    isExpanded.value = !isExpanded.value;
  }

  void _pickAudio() async {
    recorderController = RecorderController();
    bool isGranted = await recorderController.checkPermission();
    if (isGranted) {
      audioAnimationController.forward();
      recorderController.record(recorderSettings: const RecorderSettings());
    } else {
      Get.bottomSheet(
          ConfirmationSheet(
              title: LKey.enableMicrophoneAccessTitle.tr,
              description: LKey.enableMicrophoneAccessDescription.tr,
              onTap: openAppSettings,
              positiveText: LKey.settings.tr),
          isScrollControlled: true);
    }
  }

  void deleteRecordedAudio() async {
    audioAnimationController.reverse();
    recorderController.reset();
    recorderController.dispose();
  }

  void sendRecordedAudio() async {
    audioAnimationController.reverse();
    showLoader();

    try {
      String? recordedFilePath = await recorderController.stop();
      if (recordedFilePath != null) {
        List<double> waveData = await playerController.waveformExtraction.extractWaveformData(
            path: recordedFilePath, noOfSamples: playerWaveStyle.getSamplesForWidth(wavesWidth));

        String audioUrl = await _uploadFile(XFile(recordedFilePath));
        sendChatMessage(
          type: MessageType.audio,
          audioMessage: audioUrl,
          waveData: waveData,
        );
      } else {
        Loggers.error('Audio path not found');
      }
    } catch (e) {
      Loggers.error('Audio recording error: $e');
    } finally {
      stopLoader();
      recorderController.dispose();
    }
  }

  void startAudioPlayback() async {
    await playerController.startPlayer();
    playerController.setFinishMode(finishMode: FinishMode.pause);
  }

  void pauseAudioPlayback() async {
    await playerController.pausePlayer();
  }

  void toggleAudioPlayback(MessageData message) {
    if (playerValue.value.id == message.id) {
      switch (playerValue.value.state) {
        case PlayerState.initialized:
        case PlayerState.playing:
          pauseAudioPlayback();
          break;
        case PlayerState.paused:
          startAudioPlayback();
          break;
        case PlayerState.stopped:
          break;
      }
    } else {
      playAudioMessage(message);
    }
  }

  void playAudioMessage(MessageData message) async {
    String audioUrl = message.audioMessage?.addBaseURL() ?? '';
    if (audioUrl.isEmpty) return;

    DefaultCacheManager().getSingleFile(audioUrl).then((file) async {
      playerController.release();
      await playerController.preparePlayer(
        path: file.path,
        noOfSamples: playerWaveStyle.getSamplesForWidth(wavesWidth),
      );

      playerValue.value = PlayerValue(state: PlayerState.initialized, id: message.id ?? 0);
      startAudioPlayback();
    });
  }

  // ---------------------------------------------------------------------
  // DELETE / UNSEND / REQUESTS
  // ---------------------------------------------------------------------

  void onDeleteForYou(MessageData message) async {
    await Future.delayed(const Duration(milliseconds: 200));
    try {
      if (_isServerId(message.id ?? 0)) {
        await ChatService.instance.deleteMessageForMe(messageId: message.id!);
      }
      chatList.removeWhere((element) => element.id == message.id);
    } catch (e) {
      Loggers.error('On Delete For You error : $e');
    }
  }

  void onUnSend(MessageData message) async {
    await Future.delayed(const Duration(milliseconds: 200));
    try {
      if (_isServerId(message.id ?? 0)) {
        await ChatService.instance.unsendMessage(messageId: message.id!);
      }
      chatList.removeWhere((element) => element.id == message.id);
      await _deleteAssociatedFiles(message);
    } catch (e) {
      Loggers.error('Un-send message error: $e');
    }
  }

  Future<void> _deleteAssociatedFiles(MessageData message) async {
    switch (message.messageType) {
      case MessageType.image:
        await deleteFile(message.imageMessage ?? '');
        break;
      case MessageType.video:
        await deleteFile(message.videoMessage ?? '');
        await deleteFile(message.imageMessage ?? '');
        break;
      case MessageType.audio:
        await deleteFile(message.audioMessage ?? '');
        break;
      default:
        break;
    }
  }

  Future<bool> deleteFile(String file) async {
    StatusModel response = await CommonService.instance.deleteFile(file);
    if (response.status == true) return true;
    return false;
  }

  void onChatRequestTap(UserRequestAction requestType, ChatThread conversation) async {
    switch (requestType) {
      case UserRequestAction.block:
        AppUser? user = conversation.chatUser;
        blockUser(
            User(
                id: user?.userId,
                profilePhoto: user?.profile,
                username: user?.username,
                fullname: user?.fullname,
                isVerify: user?.isVerify), () {
          conversationUser.update((val) => val?.iBlocked = true);
        });
        break;
      case UserRequestAction.reject:
        if (threadId != null) {
          await ChatService.instance.rejectChatRequest(threadId: threadId!);
        }
        Get.back();
        break;
      case UserRequestAction.accept:
        if (threadId != null) {
          await ChatService.instance.acceptChatRequest(threadId: threadId!);
        }
        conversationUser.update((val) {
          val?.chatType = ChatType.approved;
          val?.requestType = UserRequestAction.accept.title;
        });
        break;
    }
  }

  // ---------------------------------------------------------------------
  // POST / STORY / REPORT / BLOCK
  // ---------------------------------------------------------------------

  void onPostTap(Post post) async {
    PostType type = post.postType;
    playerController.pausePlayer();
    fetchPost(postType: post.postType, post: post);
    switch (type) {
      case PostType.reel:
      case PostType.video:
        Get.to(() => ReelsScreen(reels: [post].obs, position: 0, pageType: ReelPageType.single));
        break;
      case PostType.image:
      case PostType.text:
        Get.to(() => SinglePostScreen(post: post, isFromNotification: false));
        break;
      case PostType.none:
        break;
    }
  }

  void fetchPost({required PostType postType, Post? post}) async {
    Post? _post = (await PostService.instance.fetchPostById(postId: post?.id ?? -1)).data?.post;
    if (_post == null) return;
    switch (postType) {
      case PostType.image:
      case PostType.text:
        Get.find<PostScreenController>(tag: _post.id.toString()).updatePost(_post);
        break;
      case PostType.reel:
      case PostType.video:
        Get.find<ReelController>(tag: _post.id.toString()).updateReelData(reel: _post);
        break;
      case PostType.none:
        break;
    }
  }

  void onReportUser(ChatThread chatThread) {
    Get.bottomSheet(ReportSheet(reportType: ReportType.user, id: chatThread.chatUser?.userId),
        isScrollControlled: true);
  }

  void toggleBlockUnblock(ChatThread chatThread) {
    if (chatThread.iBlocked ?? false) {
      unblockUser(otherUser, () {
        conversationUser.update((val) => val?.iBlocked = false);
      });
    } else {
      blockUser(otherUser, () {
        conversationUser.update((val) => val?.iBlocked = true);
      });
    }
  }

  void sendStoryReply({required Story story, required String textReply, String? imageReply}) {
    sendChatMessage(
        type: MessageType.storyReply,
        imageMessage: imageReply,
        textMessage: textReply,
        storyReplyMessage: jsonEncode(story.toJsonWithUser()));
  }

  _markAsRead() async {
    final tId = threadId;
    if (tId == null || !SessionManager.instance.isLogin()) return;
    try {
      await ChatService.instance.markThreadRead(threadId: tId);
      conversationUser.update((val) => val?.msgCount = 0);
      if (Get.isRegistered<DashboardScreenController>()) {
        Get.find<DashboardScreenController>().refreshUnreadCounts();
      }
    } catch (e) {
      Loggers.error('markThreadRead failed: $e');
    }
  }

  /// Expired/deleted story replies just blank locally — history stays on the
  /// server, the placeholder renders from the empty JSON.
  void removeStoryFromChat(MessageData message) {
    final index = chatList.indexWhere((element) => element.id == message.id);
    if (index != -1) {
      final updated = chatList[index];
      updated.storyReplyMessage = jsonEncode(Story());
      chatList[index] = updated;
    }
  }

  void onStoryTap(MessageData message, Story story) {
    final createdAtStr = story.createdAt;
    if (createdAtStr == null || createdAtStr.isEmpty) {
      removeStoryFromChat(message);
      return;
    }

    DateTime? storyDate;
    try {
      storyDate = DateTime.parse(createdAtStr);
    } catch (e) {
      removeStoryFromChat(message);
      return;
    }

    final isExpired = DateTime.now().difference(storyDate).inHours >= 24;
    if (isExpired) {
      removeStoryFromChat(message);
      return;
    }

    if (story.id == null) {
      removeStoryFromChat(message);
      return;
    }

    final user = User(
      id: story.userId,
      username: story.user?.username ?? '',
      fullname: story.user?.fullname ?? '',
      profilePhoto: story.user?.profilePhoto ?? '',
      isVerify: story.user?.isVerify,
      bio: story.user?.bio ?? '',
      stories: [story],
    );

    Get.bottomSheet(
      StoryViewSheet(
        stories: [user],
        userIndex: 0,
        onUpdateDeleteStory: (_) {},
      ),
      isScrollControlled: true,
      ignoreSafeArea: false,
      useRootNavigator: true,
    );
  }
}

final playerWaveStyle = PlayerWaveStyle(
    fixedWaveColor: ColorRes.bgGrey,
    spacing: 3,
    waveThickness: 1.5,
    scaleFactor: 50,
    liveWaveGradient: StyleRes.wavesGradient);

class PlayerValue {
  PlayerState state;
  int id;

  PlayerValue({required this.state, required this.id});
}

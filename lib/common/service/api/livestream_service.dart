import 'package:get/get.dart';
import 'package:shortzz/common/controller/app_user_cache_controller.dart';
import 'package:shortzz/common/service/api/api_service.dart';
import 'package:shortzz/common/service/utils/params.dart';
import 'package:shortzz/common/service/utils/web_service.dart';
import 'package:shortzz/model/general/status_model.dart';
import 'package:shortzz/model/livestream/app_user.dart';
import 'package:shortzz/model/livestream/livestream.dart';
import 'package:shortzz/model/livestream/livestream_comment.dart';
import 'package:shortzz/model/livestream/livestream_user_state.dart';

/// REST layer for MySQL livestream signalling (media stays on Zego; live
/// deltas arrive via RealtimeService presence channels, these endpoints are
/// the source of truth and the polling fallback).
class LivestreamService {
  LivestreamService._();

  static final LivestreamService instance = LivestreamService._();

  void _seedUserCache(Map<String, dynamic>? appUserJson) {
    if (appUserJson == null || !Get.isRegistered<AppUserCacheController>()) {
      return;
    }
    Get.find<AppUserCacheController>()
        .addAppUser(AppUser.fromJson(appUserJson));
  }

  Future<List<Livestream>> fetchLivestreams({int limit = 50}) async {
    final raw = await ApiService.instance.call<Map<String, dynamic>>(
        url: WebService.livestream.fetchLivestreams,
        param: {Params.limit: limit});
    final List<Livestream> streams = [];
    if (raw['status'] == true && raw['data'] is List) {
      for (final item in (raw['data'] as List)) {
        final json = (item as Map).cast<String, dynamic>();
        _seedUserCache((json['host_user'] as Map?)?.cast<String, dynamic>());
        streams.add(Livestream.fromJson(json));
      }
    }
    return streams;
  }

  Future<StreamStateResult> createLivestream(
      {String? description,
      required String type,
      required int isRestrictToJoin,
      int? battleDuration,
      int? hostViewId}) async {
    return ApiService.instance.call(
        url: WebService.livestream.createLivestream,
        param: {
          Params.description: description,
          Params.type: type,
          Params.isRestrictToJoin: isRestrictToJoin,
          Params.battleDuration: battleDuration,
          Params.hostViewId: hostViewId,
        },
        fromJson: StreamStateResult.fromJson);
  }

  Future<StreamStateResult> joinLivestream({required String roomId}) async {
    return ApiService.instance.call(
        url: WebService.livestream.joinLivestream,
        param: {Params.roomId: roomId},
        fromJson: StreamStateResult.fromJson);
  }

  Future<StatusModel> leaveLivestream({required String roomId}) async {
    return ApiService.instance.call(
        url: WebService.livestream.leaveLivestream,
        param: {Params.roomId: roomId},
        fromJson: StatusModel.fromJson);
  }

  Future<StreamStateResult> fetchStreamState(
      {required String roomId, int? afterCommentId}) async {
    return ApiService.instance.call(
        url: WebService.livestream.fetchStreamState,
        param: {
          Params.roomId: roomId,
          Params.afterCommentId: afterCommentId,
        },
        fromJson: StreamStateResult.fromJson);
  }

  Future<StatusModel> sendComment(
      {required String roomId,
      String? comment,
      String commentType = 'TEXT',
      int? receiverId}) async {
    return ApiService.instance.call(
        url: WebService.livestream.sendComment,
        param: {
          Params.roomId: roomId,
          Params.comment: comment,
          Params.commentType: commentType,
          Params.receiverId: receiverId,
        },
        fromJson: StatusModel.fromJson);
  }

  Future<StatusModel> addLikes(
      {required String roomId, required int count}) async {
    return ApiService.instance.call(
        url: WebService.livestream.addLikes,
        param: {Params.roomId: roomId, Params.count: count},
        fromJson: StatusModel.fromJson);
  }

  /// The single payer for stream gifts: the backend moves the coins AND
  /// records the participant earnings + gift comment.
  Future<StatusModel> sendStreamGift(
      {required String roomId,
      required int receiverUserId,
      required int giftId}) async {
    return ApiService.instance.call(
        url: WebService.livestream.sendStreamGift,
        param: {
          Params.roomId: roomId,
          Params.receiverUserId: receiverUserId,
          Params.giftId: giftId,
        },
        fromJson: StatusModel.fromJson);
  }

  Future<StatusModel> updateUserState(
      {required String roomId,
      int? userId,
      String? type,
      String? audioStatus,
      String? videoStatus}) async {
    return ApiService.instance.call(
        url: WebService.livestream.updateUserState,
        param: {
          Params.roomId: roomId,
          Params.userId: userId,
          Params.type: type,
          Params.audioStatus: audioStatus,
          Params.videoStatus: videoStatus,
        },
        fromJson: StatusModel.fromJson);
  }

  Future<StatusModel> updateBattleState(
      {required String roomId,
      required String battleType,
      String? type,
      int? battleDuration}) async {
    return ApiService.instance.call(
        url: WebService.livestream.updateBattleState,
        param: {
          Params.roomId: roomId,
          Params.battleType: battleType,
          Params.type: type,
          Params.battleDuration: battleDuration,
        },
        fromJson: StatusModel.fromJson);
  }

  Future<StatusModel> registerFollowGained(
      {required String roomId, required int userId}) async {
    return ApiService.instance.call(
        url: WebService.livestream.registerFollowGained,
        param: {Params.roomId: roomId, Params.userId: userId},
        fromJson: StatusModel.fromJson);
  }

  Future<LivestreamSummaryResult> endLivestream(
      {required String roomId}) async {
    return ApiService.instance.call(
        url: WebService.livestream.endLivestream,
        param: {Params.roomId: roomId},
        fromJson: LivestreamSummaryResult.fromJson);
  }
}

/// Full room snapshot (create/join/poll all return this shape).
class StreamStateResult {
  bool? status;
  String? message;
  Livestream? livestream;
  List<LivestreamUserState> users = [];
  List<LivestreamComment> comments = [];

  StreamStateResult.fromJson(Map<String, dynamic> json) {
    status = json['status'];
    message = json['message'];
    final data = json['data'];
    if (data is Map<String, dynamic>) {
      if (data['livestream'] is Map) {
        livestream = Livestream.fromJson(
            (data['livestream'] as Map).cast<String, dynamic>());
      }
      final cache = Get.isRegistered<AppUserCacheController>()
          ? Get.find<AppUserCacheController>()
          : null;
      for (final item in (data['users'] as List? ?? [])) {
        final userJson = (item as Map).cast<String, dynamic>();
        final state = LivestreamUserState.fromJson(userJson);
        if (state.user != null) cache?.addAppUser(state.user);
        users.add(state);
      }
      for (final item in (data['comments'] as List? ?? [])) {
        final commentJson = (item as Map).cast<String, dynamic>();
        if (commentJson['sender_user'] is Map) {
          cache?.addAppUser(AppUser.fromJson(
              (commentJson['sender_user'] as Map).cast<String, dynamic>()));
        }
        comments.add(LivestreamComment.fromJson(commentJson));
      }
    }
  }
}

class LivestreamSummaryResult {
  bool? status;
  String? message;
  int durationMs = 0;
  int totalCoins = 0;
  int followersGained = 0;
  int likeCount = 0;

  LivestreamSummaryResult.fromJson(Map<String, dynamic> json) {
    status = json['status'];
    message = json['message'];
    final data = json['data'];
    if (data is Map<String, dynamic>) {
      durationMs = data['duration_ms'] ?? 0;
      totalCoins = data['total_coins'] ?? 0;
      followersGained = data['followers_gained'] ?? 0;
      likeCount = data['like_count'] ?? 0;
    }
  }
}

import 'package:get/get.dart';
import 'package:shortzz/common/controller/app_user_cache_controller.dart';
import 'package:shortzz/common/functions/json_parse.dart';
import 'package:shortzz/model/livestream/app_user.dart';
import 'package:shortzz/utilities/app_res.dart';

class Livestream {
  int? watchingCount;
  String? description;
  LivestreamType? type;
  BattleType? battleType;
  int battleDuration = AppRes.battleDurationInMinutes;
  int? isRestrictToJoin;
  int? hostViewID;
  String? roomID;
  int? likeCount;
  int? hostId;
  List<int>? coHostIds;
  AppUser? hostUser;
  List<AppUser>? coHostUsers;
  int? createdAt;
  int? battleCreatedAt;

  /// Still frame retained after the stream ends — the live media itself is
  /// never recorded. Absolute URL, already resolved server-side.
  String? snapshot;

  Livestream(
      {this.watchingCount,
      this.description,
      this.type,
      this.battleType,
      this.isRestrictToJoin,
      this.hostViewID,
      this.roomID,
      this.likeCount,
      this.hostId,
      this.coHostIds,
      this.createdAt,
      this.battleCreatedAt,
      this.battleDuration = AppRes.battleDurationInMinutes});

  Livestream.fromJson(Map<String, dynamic> json) {
    type = LivestreamType.fromString(json['type']);
    battleType = BattleType.fromString(json['battle_type']);
    watchingCount = asIntOrNull(json['watching_count']);
    description = asStringOrNull(json['description']);
    isRestrictToJoin = asIntOrNull(json['is_restrict_to_join']);
    hostViewID = asIntOrNull(json['host_view_id']);
    roomID = asStringOrNull(json['room_id']);
    likeCount = asIntOrNull(json['like_count']);
    hostId = asIntOrNull(json['host_id']);
    coHostIds = (json['co-host_ids'] as List?)
            ?.map(asIntOrNull)
            .whereType<int>()
            .toList() ??
        [];
    createdAt = asIntOrNull(json['created_at']);
    battleCreatedAt = asIntOrNull(json['battle_created_at']);
    snapshot = asStringOrNull(json['snapshot']);
    battleDuration =
        asIntOrNull(json['battle_duration']) ?? AppRes.battleDurationInMinutes;
  }

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> data = <String, dynamic>{};
    data['watching_count'] = watchingCount;
    data['description'] = description;
    data['type'] = type?.value;
    data['battle_type'] = battleType?.value;
    data['is_restrict_to_join'] = isRestrictToJoin;
    data['host_view_id'] = hostViewID;
    data['room_id'] = roomID;
    data['like_count'] = likeCount;
    data['host_id'] = hostId;
    data['co-host_ids'] = coHostIds;
    data['created_at'] = createdAt;
    data['battle_created_at'] = battleCreatedAt;
    data['snapshot'] = snapshot;
    data['battle_duration'] = battleDuration;
    return data;
  }

  List<AppUser> getAllUsers(List<AppUser> users) {
    AppUser? hostUser =
        users.firstWhereOrNull((element) => element.userId == hostId);
    final coHostUsers = coHostIds
            ?.map((id) => users.firstWhereOrNull((user) => user.userId == id))
            .whereType<AppUser>()
            .toList() ??
        [];

    final allUsers = [if (hostUser != null) hostUser, ...coHostUsers];
    return allUsers;
  }

  AppUser? getHostUser(List<AppUser> users) {
    final controller = Get.find<AppUserCacheController>();
    AppUser? hostUser = controller.users
        .firstWhereOrNull((element) => element.userId == hostId);
    return hostUser;
  }

  List<AppUser> getCoHostUsers(List<AppUser> users) {
    final coHostUsers = coHostIds
            ?.map((id) => users.firstWhereOrNull((user) => user.userId == id))
            .whereType<AppUser>()
            .toList() ??
        [];
    return coHostUsers;
  }
}

enum LivestreamType {
  livestream('LIVESTREAM'),
  battle('BATTLE');

  final String value;

  const LivestreamType(this.value);

  static LivestreamType fromString(dynamic value) {
    return LivestreamType.values.firstWhereOrNull((e) => e.value == value) ??
        LivestreamType.livestream;
  }
}

enum BattleType {
  initiate('INITIATE'),
  waiting('WAITING'),
  running('RUNNING'),
  end('END');

  final String value;

  const BattleType(this.value);

  static BattleType fromString(String? value) {
    return BattleType.values.firstWhereOrNull((e) => e.value == value) ??
        BattleType.initiate;
  }
}

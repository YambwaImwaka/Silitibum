import 'package:get/get.dart';
import 'package:shortzz/common/controller/base_controller.dart';
import 'package:shortzz/common/extensions/user_extension.dart';
import 'package:shortzz/common/service/api/user_service.dart';
import 'package:shortzz/model/livestream/app_user.dart';
import 'package:shortzz/model/user_model/user_model.dart';

/// In-memory cache of compact user profiles for chat threads, messages and
/// livestream comments (replaces the Firestore `app_users` collection).
/// Server payloads embed user summaries which are seeded here; anything
/// missing is fetched once over REST.
class AppUserCacheController extends BaseController {
  RxList<AppUser> users = <AppUser>[].obs;

  static final instance = AppUserCacheController();

  final Set<int> _inFlight = {};

  void fetchUserIfNeeded(int userId) {
    if (userId <= 0 ||
        users.any((element) => element.userId == userId) ||
        _inFlight.contains(userId)) {
      return;
    }
    _inFlight.add(userId);
    UserService.instance.fetchUserDetails(userId: userId).then((value) {
      _inFlight.remove(userId);
      addUser(value);
    }).catchError((_) {
      _inFlight.remove(userId);
    });
  }

  /// Upserts from a full backend user.
  void addUser(User? user) {
    if (user == null) return;
    addAppUser(user.appUser);
  }

  void updateUser(User? user) => addUser(user);

  /// Upserts a compact profile (embedded summaries from chat/livestream
  /// payloads land here).
  void addAppUser(AppUser? user) {
    if (user == null || user.userId == null) return;
    final index = users.indexWhere((element) => element.userId == user.userId);
    if (index != -1) {
      users[index] = user;
    } else {
      users.add(user);
    }
  }

  Future<void> deleteUser(int? userId) async {
    if (userId == null) return;
    users.removeWhere((element) => element.userId == userId);
  }
}

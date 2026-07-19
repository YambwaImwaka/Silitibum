import 'package:get/get.dart';
import 'package:shortzz/common/controller/base_controller.dart';
import 'package:shortzz/common/controller/follow_controller.dart';
import 'package:shortzz/common/service/api/user_service.dart';
import 'package:shortzz/common/widget/confirmation_dialog.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/model/general/status_model.dart';
import 'package:shortzz/model/user_model/user_model.dart';

/// Block/unblock actions. The backend is the single source of truth for
/// block state — chat payloads compute i_blocked/i_am_blocked from the block
/// table on every fetch, so no client-side state fan-out is needed.
class BlockUserController extends BaseController {
  void blockUser(User? user, Function() completion) {
    Get.bottomSheet(
        ConfirmationSheet(
          title: LKey.blockUser.trParams({'username': user?.username ?? ''}),
          description: LKey.blockUserConfirmation.tr,
          positiveText: LKey.block.tr,
          onTap: () async {
            if (user?.isFollowing == true) {
              FollowController followController;
              if (Get.isRegistered<FollowController>(
                  tag: user?.id.toString())) {
                followController =
                    Get.find<FollowController>(tag: user?.id.toString());
                followController.updateUser(user);
              } else {
                followController = Get.put(FollowController(user.obs),
                    tag: user?.id.toString());
              }
              await followController.followUnFollowUser();
            }
            StatusModel response =
                await UserService.instance.blockUser(userId: user?.id ?? -1);
            if (response.status == true) {
              completion.call();
            }
          },
        ),
        isScrollControlled: true);
  }

  Future<void> unblockUser(User? user, Function() completion) async {
    await Get.bottomSheet(
        ConfirmationSheet(
          title: LKey.unblockUser.trParams({'username': user?.username ?? ''}),
          description: LKey.unblockUserConfirmation.tr,
          positiveText: LKey.unBlock.tr,
          onTap: () async {
            StatusModel response =
                await UserService.instance.unBlockUser(userId: user?.id ?? -1);
            if (response.status == true) {
              completion.call();
            }
          },
        ),
        isScrollControlled: true);
  }
}

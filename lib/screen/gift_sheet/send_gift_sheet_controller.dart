import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:shortzz/common/controller/base_controller.dart';
import 'package:shortzz/common/functions/debounce_action.dart';
import 'package:shortzz/common/manager/haptic_manager.dart';
import 'package:shortzz/common/manager/logger.dart';
import 'package:shortzz/common/manager/session_manager.dart';
import 'package:shortzz/common/service/api/gift_wallet_service.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/model/general/settings_model.dart';
import 'package:shortzz/model/livestream/app_user.dart';
import 'package:shortzz/model/post_story/post_model.dart';
import 'package:shortzz/model/user_model/user_model.dart';
import 'package:shortzz/screen/coin_wallet_screen/coin_wallet_screen.dart';
import 'package:shortzz/screen/gift_sheet/send_gift_dialog.dart';
import 'package:shortzz/screen/gift_sheet/send_gift_sheet.dart';
import 'package:shortzz/screen/live_stream/livestream_screen/livestream_screen_controller.dart';

class SendGiftSheetController extends BaseController {
  Rx<Setting?> settings = Rx<Setting?>(null);
  Rx<User?> myUser = Rx<User?>(null);
  int? userId;
  List<AppUser> liveUsers;
  GiftType? giftType;
  late LivestreamScreenController livestreamController;

  SendGiftSheetController(this.giftType, this.userId, this.liveUsers);

  @override
  void onInit() {
    super.onInit();
    _initData();

    if (liveUsers.isNotEmpty &&
        (giftType == GiftType.livestream || giftType == GiftType.battle)) {
      livestreamController = Get.find<LivestreamScreenController>();
      if (livestreamController.selectedGiftUser.value == null) {
        livestreamController.selectedGiftUser = liveUsers.first.obs;
      } else {
        DebounceAction.tagged('gift_user_select').call(() {
          livestreamController.selectedGiftUser.value = liveUsers.firstWhere(
              (element) =>
                  element.userId ==
                  livestreamController.selectedGiftUser.value?.userId,
              orElse: () => liveUsers.first);
        });
      }
    }
  }

  _initData() {
    settings.value = SessionManager.instance.getSettings();
    myUser.value = SessionManager.instance.getUser();
  }

  void onGiftTap(Gift gift, BuildContext context) {
    if (gift.id == null) {
      return showSnackBar('Gift Not Found');
    }

    if ((gift.coinPrice ?? 0) > (myUser.value?.coinWallet ?? 0)) {
      showSnackBar(LKey.insufficientFundGetCoins.tr);
      // Route the user straight to the coins hub (IAP + mobile-money top-up)
      // instead of dead-ending here.
      Get.to(() => const CoinWalletScreen());
      return;
    }

    sendGift(gift, context);
  }

  Future<void> sendGift(Gift gift, BuildContext context) async {
    final giftId = gift.id?.toInt() ?? -1;

    final coinPrice = gift.coinPrice ?? 0;
    userId ??= livestreamController.selectedGiftUser.value?.userId;

    if (giftId == -1 || userId == -1) {
      return Loggers.error('Invalid Gift: $giftId or User: $userId');
    }

    if (coinPrice <= 0) {
      return Loggers.error(
          'Invalid coin price: $coinPrice, skipping gift sending.');
    }

    // Stream/battle gifts are paid by the livestream endpoint (the caller's
    // onCompletion) — paying here too would double-charge the wallet.
    if (giftType != GiftType.none) {
      Get.back(
          result: GiftManager(gift,
              streamUser: livestreamController.selectedGiftUser.value));
      return;
    }

    final response = await withLoader(() =>
        GiftWalletService.instance.sendGift(giftId: giftId, userId: userId));
    if (response == null) return;
    if (response.status == true) {
      // Deduct gift coins from user wallet
      myUser.update((val) {
        val?.removeCoinFromWallet(coinPrice);
      });
      Loggers.info(myUser.value?.coinWallet);
      SessionManager.instance.setUser(myUser.value);
      Get.back(result: GiftManager(gift));
    } else {
      showSnackBar(response.message);
    }
  }
}

class GiftManager {
  Gift gift;
  AppUser? streamUser;

  GiftManager(this.gift, {this.streamUser});

  static Future<void> openGiftSheet(
      {int? userId,
      Post? post,
      GiftType giftType = GiftType.none,
      BattleView battleViewType = BattleView.red,
      List<AppUser> streamUsers = const [],
      required Function(GiftManager giftManager) onCompletion}) async {
    await Get.bottomSheet<GiftManager>(
      SendGiftSheet(
        userId: userId,
        giftType: giftType,
        battleViewType: battleViewType,
        streamUsers: streamUsers,
      ),
      isScrollControlled: true,
    ).then((gift) {
      if (gift != null) {
        onCompletion(gift);
      }
    });
  }

  static void showAnimationDialog(Gift gift) {
    showGeneralDialog(
      context: Get.context!,
      pageBuilder: (context, animation, secondaryAnimation) {
        return SendGiftDialog(gift: gift);
      },
      transitionDuration: const Duration(milliseconds: 400),
      transitionBuilder: (context, animation, secondaryAnimation, child) {
        final slideAnimation = Tween<Offset>(
          begin: const Offset(0, 1),
          end: Offset.zero,
        ).animate(animation);

        if (slideAnimation.isForwardOrCompleted) {
          HapticManager.shared.light();
        }

        return SlideTransition(
          position: slideAnimation,
          child: FadeTransition(
            opacity: animation,
            child: child,
          ),
        );
      },
    );
  }

  /// No-op: the gift notification is sent server-side when the coins move
  /// (GlobalFunction::sendGift), so it cannot be lost if this device is
  /// backgrounded mid-transfer. Kept as a stub because several sheets call it.
  static void sendNotification(Post? post) {}
}

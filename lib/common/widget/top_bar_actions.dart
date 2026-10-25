import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:shortzz/common/functions/auth_gate.dart';
import 'package:shortzz/common/manager/session_manager.dart';
import 'package:shortzz/screen/dashboard_screen/dashboard_screen_controller.dart';
import 'package:shortzz/screen/explore_screen/explore_screen.dart';
import 'package:shortzz/screen/message_screen/message_screen.dart';
import 'package:shortzz/screen/notification_screen/notification_screen.dart';
import 'package:shortzz/utilities/asset_res.dart';
import 'package:shortzz/utilities/text_style_custom.dart';
import 'package:shortzz/utilities/theme_res.dart';

/// Search and the activity bell. Every main tab's top bar carries them, so
/// new follows, gifts, likes and comments are one tap away wherever you are;
/// the bell used to be on the Feed tab only.
class TopBarActions extends StatelessWidget {
  final Color color;

  const TopBarActions({super.key, required this.color});

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      spacing: 10,
      children: [
        TopBarIcon(
          onTap: () => Get.to(() => const ExplorePage()),
          child: Image.asset(AssetRes.icSearch,
              width: 24, height: 24, color: color),
        ),
        NotificationBell(color: color),
      ],
    );
  }
}

/// Opens Activity; the badge counts what arrived since it was last opened.
class NotificationBell extends StatelessWidget {
  final Color color;

  const NotificationBell({super.key, required this.color});

  static void open() {
    if (!AuthGate.check()) return;
    // Cleared now for instant feedback; the screen's first page confirms it.
    SessionManager.instance.setUnreadActivity(0);
    Get.to(() => const NotificationScreen());
  }

  @override
  Widget build(BuildContext context) {
    return TopBarIcon(
      onTap: open,
      child: Obx(() => CountBadge(
            count: SessionManager.instance.notifyCount.value,
            child: Image.asset(AssetRes.icNotification,
                width: 24, height: 24, color: color),
          )),
    );
  }
}

/// Messages moved off the bottom bar to the profile; the badge counts chats
/// with unread messages.
class MessagesButton extends StatelessWidget {
  final Color color;

  const MessagesButton({super.key, required this.color});

  @override
  Widget build(BuildContext context) {
    final RxInt unread = Get.isRegistered<DashboardScreenController>()
        ? Get.find<DashboardScreenController>().unReadCount
        : 0.obs;
    return TopBarIcon(
      onTap: () {
        if (!AuthGate.check()) return;
        Get.to(() => const MessagesPage());
      },
      child: Obx(() => CountBadge(
            count: unread.value,
            child: Image.asset(AssetRes.icChat,
                width: 25, height: 25, color: color),
          )),
    );
  }
}

/// A top-bar icon with a comfortable touch target.
class TopBarIcon extends StatelessWidget {
  final VoidCallback onTap;
  final Widget child;

  const TopBarIcon({super.key, required this.onTap, required this.child});

  @override
  Widget build(BuildContext context) {
    return InkResponse(
      onTap: onTap,
      radius: 22,
      child: Padding(padding: const EdgeInsets.all(5), child: child),
    );
  }
}

class CountBadge extends StatelessWidget {
  final int count;
  final Widget child;

  const CountBadge({super.key, required this.count, required this.child});

  @override
  Widget build(BuildContext context) {
    return Stack(
      clipBehavior: Clip.none,
      children: [
        child,
        if (count > 0)
          Positioned(
            top: -6,
            right: -8,
            child: Container(
              constraints: const BoxConstraints(minWidth: 18, minHeight: 18),
              padding: const EdgeInsets.symmetric(horizontal: 4),
              alignment: Alignment.center,
              decoration: BoxDecoration(
                color: themeAccentSolid(context),
                borderRadius: BorderRadius.circular(9),
              ),
              child: Text(count > 9 ? '9+' : '$count',
                  style: TextStyleCustom.outFitMedium500(
                      color: whitePure(context), fontSize: 11)),
            ),
          ),
      ],
    );
  }
}

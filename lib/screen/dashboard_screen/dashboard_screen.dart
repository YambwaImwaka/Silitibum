import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:proste_indexed_stack/proste_indexed_stack.dart';
import 'package:shortzz/common/widget/banner_ads_custom.dart';
import 'package:shortzz/common/widget/top_bar_actions.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/model/user_model/user_model.dart';
import 'package:shortzz/screen/dashboard_screen/dashboard_screen_controller.dart';
import 'package:shortzz/screen/feed_screen/feed_screen.dart';
import 'package:shortzz/screen/home_screen/home_screen.dart';
import 'package:shortzz/screen/live_stream/live_feed_screen/live_feed_screen.dart';
import 'package:shortzz/screen/profile_screen/profile_screen.dart';
import 'package:shortzz/utilities/asset_res.dart';
import 'package:shortzz/utilities/style_res.dart';
import 'package:shortzz/utilities/text_style_custom.dart';
import 'package:shortzz/utilities/theme_res.dart';

class DashboardScreen extends StatelessWidget {
  final User? myUser;

  const DashboardScreen({super.key, this.myUser});

  @override
  Widget build(BuildContext context) {
    final controller = Get.put(DashboardScreenController());
    return Scaffold(
      backgroundColor: scaffoldBackgroundColor(context),
      resizeToAvoidBottomInset: true,
      body: Obx(() {
        final int index = controller.selectedPageIndex.value;
        return Column(
          children: [
            Expanded(
              child: ProsteIndexedStack(
                index: index,
                children: [
                  // Tabs build on first visit and then stay alive; building
                  // them all up front kept every heavy screen (video players,
                  // story lists, profile) resident from launch.
                  IndexedStackChild(child: const HomeScreen(), preload: false),
                  IndexedStackChild(
                      child: FeedScreen(myUser: myUser), preload: false),
                  IndexedStackChild(
                      child: const LiveFeedScreen(), preload: false),
                  IndexedStackChild(
                      child: ProfileScreen(
                          isDashBoard: true,
                          user: myUser,
                          isTopBarVisible: false),
                      preload: false),
                ],
              ),
            ),
            // Not over full-screen video (Snaps, Live).
            if (!DashboardScreenController.isVideoTab(index))
              const BannerAdsCustom(),
          ],
        );
      }),
      bottomNavigationBar: _BottomBar(controller: controller),
    );
  }
}

/// Snaps · Feed · ➕ · Live · Profile, on a floating pill. Creating sits in the
/// middle of the bar (it used to hide in the Snaps top bar); Explore is the
/// search icon on each tab's top bar and Messages is on the profile.
class _BottomBar extends StatelessWidget {
  final DashboardScreenController controller;

  const _BottomBar({required this.controller});

  @override
  Widget build(BuildContext context) {
    return Obx(() {
      final PostUploadingProgress upload = controller.postProgress.value;
      final bool isUploading = upload.uploadType != UploadType.none;
      final bool onVideo = DashboardScreenController.isVideoTab(
          controller.selectedPageIndex.value);
      return Container(
        color: onVideo ? Colors.black : scaffoldBackgroundColor(context),
        child: SafeArea(
          top: false,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              if (isUploading) _UploadProgressStrip(upload: upload),
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 10),
                child: Container(
                  height: 64,
                  decoration: BoxDecoration(
                    color: const Color(0xFF1C1C1E),
                    borderRadius: BorderRadius.circular(32),
                    border: Border.all(color: Colors.white.withValues(alpha: .06)),
                    boxShadow: const [
                      BoxShadow(
                          color: Color(0x40000000),
                          blurRadius: 16,
                          offset: Offset(0, 6)),
                    ],
                  ),
                  child: Row(
                    children: [
                      _NavItem(
                          controller: controller,
                          tab: DashboardScreenController.homeTab,
                          icon: AssetRes.icReel,
                          label: LKey.reels.tr),
                      _NavItem(
                          controller: controller,
                          tab: DashboardScreenController.feedTab,
                          icon: AssetRes.icPost,
                          label: LKey.feed.tr),
                      _CreateButton(onTap: controller.openCreateSheet),
                      _NavItem(
                          controller: controller,
                          tab: DashboardScreenController.liveTab,
                          icon: AssetRes.icLiveStream,
                          label: LKey.live.tr),
                      _NavItem(
                          controller: controller,
                          tab: DashboardScreenController.profileTab,
                          icon: AssetRes.icProfile,
                          label: LKey.profile.tr,
                          // Messages live on the profile now.
                          badge: controller.unReadCount),
                    ],
                  ),
                ),
              ),
            ],
          ),
        ),
      );
    });
  }
}

class _NavItem extends StatelessWidget {
  final DashboardScreenController controller;
  final int tab;
  final String icon;
  final String label;
  final RxInt? badge;

  const _NavItem(
      {required this.controller,
      required this.tab,
      required this.icon,
      required this.label,
      this.badge});

  @override
  Widget build(BuildContext context) {
    return Expanded(
      child: InkResponse(
        onTap: () => controller.onChanged(tab),
        radius: 34,
        child: Obx(() {
          final bool selected = controller.selectedPageIndex.value == tab;
          final Color color = selected
              ? themeAccentSolid(context)
              : Colors.white.withValues(alpha: .62);
          Widget image = Image.asset(icon, width: 26, height: 26, color: color);
          final RxInt? count = badge;
          if (count != null) {
            image = CountBadge(count: count.value, child: image);
          }
          return Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              AnimatedScale(
                scale: selected ? controller.scaleValue.value : 1.0,
                duration: const Duration(milliseconds: 300),
                child: image,
              ),
              const SizedBox(height: 3),
              Text(label,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyleCustom.outFitMedium500(
                      fontSize: 11,
                      color: selected
                          ? Colors.white
                          : Colors.white.withValues(alpha: .62))),
            ],
          );
        }),
      ),
    );
  }
}

class _CreateButton extends StatelessWidget {
  final VoidCallback onTap;

  const _CreateButton({required this.onTap});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 6),
      child: InkResponse(
        onTap: onTap,
        radius: 30,
        child: Container(
          width: 50,
          height: 50,
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            gradient: StyleRes.themeGradient,
            boxShadow: const [
              BoxShadow(
                  color: Color(0x55E05514),
                  blurRadius: 12,
                  offset: Offset(0, 4)),
            ],
          ),
          child: const Icon(Icons.add_rounded, color: Colors.white, size: 32),
        ),
      ),
    );
  }
}

/// Upload progress for a post or story being published in the background.
class _UploadProgressStrip extends StatelessWidget {
  final PostUploadingProgress upload;

  const _UploadProgressStrip({required this.upload});

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 30,
      child: Stack(
        alignment: Alignment.center,
        children: [
          Container(decoration: BoxDecoration(gradient: StyleRes.themeGradient)),
          Align(
            alignment: AlignmentDirectional.centerEnd,
            child: LayoutBuilder(builder: (context, constraints) {
              final double done =
                  (constraints.maxWidth * upload.progress) / 100;
              return AnimatedContainer(
                height: 30,
                width: constraints.maxWidth - done,
                duration: const Duration(milliseconds: 250),
                decoration: BoxDecoration(color: textDarkGrey(context)),
              );
            }),
          ),
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              if (upload.uploadType != UploadType.error)
                Text('${upload.progress.toInt()}%',
                    style: TextStyleCustom.outFitMedium500(
                        color: whitePure(context), fontSize: 16)),
              Text(' ${upload.uploadType.title(upload.type)}',
                  style: TextStyleCustom.outFitLight300(
                      color: whitePure(context), fontSize: 14)),
            ],
          ),
        ],
      ),
    );
  }
}

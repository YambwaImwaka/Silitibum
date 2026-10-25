import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:shortzz/common/manager/session_manager.dart';
import 'package:shortzz/common/widget/custom_back_button.dart';
import 'package:shortzz/common/widget/top_bar_actions.dart';
import 'package:shortzz/screen/reels_screen/reels_screen_controller.dart';
import 'package:shortzz/screen/reels_screen/widget/ad_slot.dart';
import 'package:shortzz/screen/reels_screen/widget/reel_page_type.dart';
import 'package:shortzz/utilities/asset_res.dart';
import 'package:shortzz/utilities/theme_res.dart';

class ReelsTopBar extends StatelessWidget {
  final ReelsScreenController controller;
  final Widget? widget;

  const ReelsTopBar({super.key, required this.controller, this.widget});

  @override
  Widget build(BuildContext context) {
    // The screen's Stack expands its children: anchor the bar to the top
    // rather than letting it fill (and centre itself in) the whole video.
    if (controller.reelPageType == ReelPageType.home) {
      return Align(
          alignment: Alignment.topCenter,
          child: _HomeTopBar(controller: controller, center: widget));
    }
    return Align(
      alignment: Alignment.topCenter,
      child: SafeArea(
      bottom: false,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 15.0),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          crossAxisAlignment: CrossAxisAlignment.center,
          children: [
            CustomBackButton(
                color: whitePure(context),
                height: 30,
                width: 30,
                padding: EdgeInsets.zero,
                image: AssetRes.icBackArrow_1),
            if (widget != null) Flexible(child: widget!),
            Obx(() {
              if (controller.reels.isEmpty) {
                return const SizedBox(width: 30, height: 30);
              }

              bool isVisible = controller.reels[controller.position.value].userId !=
                  SessionManager.instance.getUserID();

              return Visibility(
                visible: isVisible,
                replacement: const SizedBox(width: 30, height: 30),
                child: InkWell(
                  onTap: controller.onReportTap,
                  child: Image.asset(AssetRes.icAlert, width: 30, height: 30),
                ),
              );
            })
          ],
        ),
      ),
      ),
    );
  }
}

/// The Snaps tab's bar: the feed picker (Discover/Following…) centred, and
/// report, search and the activity bell on the right. A soft shade behind it
/// keeps it readable over a bright video. The ➕ moved to the bottom bar and
/// the "Snaps" title went with it — the bottom bar already says where you are.
class _HomeTopBar extends StatelessWidget {
  final ReelsScreenController controller;
  final Widget? center;

  const _HomeTopBar({required this.controller, this.center});

  @override
  Widget build(BuildContext context) {
    return Stack(
      children: [
        // Decoration only: taps go through to the video underneath.
        const Positioned.fill(
          child: IgnorePointer(
            child: DecoratedBox(
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment.topCenter,
                  end: Alignment.bottomCenter,
                  colors: [Color(0x99000000), Color(0x00000000)],
                ),
              ),
            ),
          ),
        ),
        SafeArea(
          bottom: false,
          child: SizedBox(
            height: 52,
            child: Stack(
              alignment: Alignment.center,
              children: [
                if (center != null) center!,
                Positioned(
                  right: 10,
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      _ReportAction(controller: controller),
                      TopBarActions(color: whitePure(context)),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }
}

/// Report flag for the reel currently on screen. Hidden on your own posts and
/// on sponsored slots — this widget reads the index independently of the
/// PageView's itemBuilder, so without the ad check a sponsored card would show
/// a live report flag and filing it would report the sentinel post id.
class _ReportAction extends StatelessWidget {
  const _ReportAction({required this.controller});

  final ReelsScreenController controller;

  @override
  Widget build(BuildContext context) {
    return Obx(() {
      final reels = controller.reels;
      final index = controller.position.value;

      if (reels.isEmpty ||
          index < 0 ||
          index >= reels.length ||
          AdSlot.isAdSlot(reels[index])) {
        return const SizedBox();
      }

      if (reels[index].userId == SessionManager.instance.getUserID()) {
        return const SizedBox();
      }

      return TopBarIcon(
        onTap: controller.onReportTap,
        child: Image.asset(AssetRes.icAlert, width: 24, height: 24),
      );
    });
  }
}

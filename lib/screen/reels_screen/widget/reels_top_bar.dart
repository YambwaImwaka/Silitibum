import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:shortzz/common/manager/session_manager.dart';
import 'package:shortzz/common/widget/custom_back_button.dart';
import 'package:shortzz/screen/reels_screen/reels_screen_controller.dart';
import 'package:shortzz/screen/reels_screen/widget/ad_slot.dart';
import 'package:shortzz/screen/reels_screen/widget/reel_page_type.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/utilities/asset_res.dart';
import 'package:shortzz/utilities/text_style_custom.dart';
import 'package:shortzz/utilities/theme_res.dart';

class ReelsTopBar extends StatelessWidget {
  final ReelsScreenController controller;
  final Widget? widget;

  const ReelsTopBar({super.key, required this.controller, this.widget});

  @override
  Widget build(BuildContext context) {
    return Stack(
      children: [
        SafeArea(
          bottom: false,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 15.0),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              crossAxisAlignment: CrossAxisAlignment.center,
              children: [
                if (controller.reelPageType == ReelPageType.home)
                  // The feed's wordmark, top-left — the way Instagram labels
                  // Reels. The report action this slot used to hold now sits
                  // beside the add button on the right.
                  Text(
                    LKey.reels.tr,
                    style: TextStyleCustom.unboundedBold700(
                        fontSize: 20, color: whitePure(context)),
                  )
                else
                  Visibility(
                    visible: controller.reelPageType != ReelPageType.home,
                    replacement: const SizedBox(width: 30),
                    child: CustomBackButton(
                        color: whitePure(context),
                        height: 30,
                        width: 30,
                        padding: EdgeInsets.zero,
                        image: AssetRes.icBackArrow_1),
                  ),
                if (widget != null) Flexible(child: widget!),
                if (controller.reelPageType == ReelPageType.home)
                  Row(
                    mainAxisSize: MainAxisSize.min,
                    spacing: 12,
                    children: [
                      _ReportAction(controller: controller),
                      InkWell(
                          onTap: controller.openPostOptionsSheet,
                          child: Image.asset(
                            AssetRes.icAdd,
                            width: 30,
                            height: 30,
                          )),
                    ],
                  )
                else
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
        return const SizedBox(width: 30, height: 30);
      }

      if (reels[index].userId == SessionManager.instance.getUserID()) {
        return const SizedBox(width: 30, height: 30);
      }

      return InkWell(
        onTap: controller.onReportTap,
        child: Image.asset(AssetRes.icAlert, width: 30, height: 30),
      );
    });
  }
}

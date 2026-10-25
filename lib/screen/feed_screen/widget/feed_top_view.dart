import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:shortzz/common/widget/custom_popup_menu_button.dart';
import 'package:shortzz/common/widget/top_bar_actions.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/screen/feed_screen/feed_screen_controller.dart';
import 'package:shortzz/utilities/asset_res.dart';
import 'package:shortzz/utilities/text_style_custom.dart';
import 'package:shortzz/utilities/theme_res.dart';

class FeedTopView extends StatelessWidget {
  final FeedScreenController controller;

  const FeedTopView({super.key, required this.controller});

  @override
  Widget build(BuildContext context) {
    return Container(
      color: scaffoldBackgroundColor(context),
      padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 20),
      child: SafeArea(
        bottom: false,
        child: Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            CustomPopupMenuButton(
                items: [
                  MenuItem(LKey.discover.tr,
                      () => controller.onChangeCategory(PostCategory.discover)),
                  MenuItem(LKey.nearby.tr,
                      () => controller.onChangeCategory(PostCategory.nearby)),
                  MenuItem(
                      LKey.following.tr,
                      () =>
                          controller.onChangeCategory(PostCategory.following)),
                ],
                child: Obx(
                  () => Row(
                    children: [
                      Text(
                          controller.selectedPostCategory.value.title
                              .toUpperCase(),
                          style: TextStyleCustom.unboundedBold700(
                              color: textDarkGrey(context), fontSize: 15)),
                      const SizedBox(width: 3),
                      Image.asset(AssetRes.icDownArrow,
                          color: textDarkGrey(context), height: 8, width: 8),
                    ],
                  ),
                )),
            TopBarActions(color: textDarkGrey(context)),
          ],
        ),
      ),
    );
  }
}

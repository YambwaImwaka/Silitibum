import 'package:detectable_text_field/detectable_text_field.dart';
import 'package:figma_squircle_updated/figma_squircle.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/screen/comment_sheet/helper/comment_helper.dart';
import 'package:shortzz/screen/create_feed_screen/create_feed_screen_controller.dart';
import 'package:shortzz/screen/create_feed_screen/widget/hash_tag_sheet.dart';
import 'package:shortzz/screen/create_feed_screen/widget/location_sheet.dart';
import 'package:shortzz/screen/create_feed_screen/widget/mention_sheet.dart';
import 'package:shortzz/utilities/text_style_custom.dart';
import 'package:shortzz/utilities/theme_res.dart';

class FeedTextFieldView extends StatefulWidget {
  const FeedTextFieldView({super.key});

  @override
  State<FeedTextFieldView> createState() => _FeedTextFieldViewState();
}

class _FeedTextFieldViewState extends State<FeedTextFieldView> {
  // Held in State rather than rebuilt inline. As a build-local it changed
  // identity on every rebuild, which re-inflated the DetectableTextField
  // subtree underneath it — and the onTap handler's
  // `Scrollable.ensureVisible(globalKey.currentContext!)` fires 350ms later,
  // by which point a rebuild could have left it pointing at a detached
  // element and thrown on the `!`.
  final GlobalKey globalKey = GlobalKey();

  @override
  Widget build(BuildContext context) {
    final controller = Get.find<CreateFeedScreenController>();
    CommentHelper helper = controller.commentHelper;
    return Container(
      key: globalKey,
      height: 180,
      color: bgLightGrey(context),
      margin: const EdgeInsets.symmetric(vertical: 5),
      child: Column(
        children: [
          Expanded(
            child: DetectableTextField(
              onTap: () async {
                await Future.delayed(const Duration(milliseconds: 350));
                Scrollable.ensureVisible(globalKey.currentContext!,
                    duration: const Duration(milliseconds: 350));
              },
              keyboardType: TextInputType.twitter,
              textInputAction: TextInputAction.newline,
              expands: true,
              minLines: null,
              maxLines: null,
              focusNode: helper.detectableTextFocusNode,
              controller: helper.detectableTextController,
              decoration: InputDecoration(
                  isDense: true,
                  border: InputBorder.none,
                  contentPadding:
                      const EdgeInsets.symmetric(horizontal: 15, vertical: 10),
                  hintText: LKey.writeSomethingHere.tr,
                  hintStyle: TextStyleCustom.outFitLight300(
                      color: textLightGrey(context), fontSize: 16)),
              style: TextStyleCustom.outFitRegular400(
                  color: textDarkGrey(context), fontSize: 16),
              onChanged: helper.onChanged,
            ),
          ),
          Container(
            margin: const EdgeInsets.symmetric(horizontal: 8),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceAround,
              children: List.generate(
                FeedTagType.values.length,
                (index) {
                  FeedTagType data = FeedTagType.values[index];
                  return IconWithText(
                    icon: data.image,
                    text: data.title,
                    onTap: () {
                      helper.detectableTextFocusNode.unfocus();
                      switch (index) {
                        case 0:
                          Get.bottomSheet(const MentionSheet(),
                                  isScrollControlled: true)
                              .then((value) {
                            if (helper
                                    .detectableTextController.typingDetection ==
                                null) {
                              return;
                            }
                            helper.onChanged(helper
                                    .detectableTextController.typingDetection
                                    ?.split('@')[1] ??
                                '');
                          });
                        case 1:
                          Get.bottomSheet(const HashTagSheet(),
                                  isScrollControlled: true)
                              .then((value) {
                            if (helper
                                    .detectableTextController.typingDetection ==
                                null) {
                              return;
                            }
                            helper.onChanged(helper
                                    .detectableTextController.typingDetection
                                    ?.split('#')[1] ??
                                '');
                          });
                        case 2:
                          Get.bottomSheet(
                              LocationSheet(
                                  onLocationTap: controller.onLocationTap),
                              isScrollControlled: true);
                      }
                    },
                  );
                },
              ),
            ),
          )
        ],
      ),
    );
  }
}

class IconWithText extends StatelessWidget {
  final String icon;
  final String text;
  final VoidCallback? onTap;

  const IconWithText(
      {super.key, required this.icon, required this.text, this.onTap});

  @override
  Widget build(BuildContext context) {
    return Expanded(
      child: InkWell(
        onTap: onTap,
        child: Container(
          height: 40,
          alignment: Alignment.center,
          margin: const EdgeInsets.symmetric(vertical: 8, horizontal: 5),
          decoration: ShapeDecoration(
            color: bgGrey(context),
            shape: SmoothRectangleBorder(
                borderRadius:
                    SmoothBorderRadius(cornerRadius: 8, cornerSmoothing: 1)),
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Image.asset(icon,
                  color: textDarkGrey(context), height: 19, width: 19),
              const SizedBox(width: 5),
              Text(text,
                  style: TextStyleCustom.outFitLight300(
                      color: textDarkGrey(context)))
            ],
          ),
        ),
      ),
    );
  }
}

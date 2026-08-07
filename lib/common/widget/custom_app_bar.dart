import 'package:flutter/cupertino.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:shortzz/common/widget/custom_back_button.dart';
import 'package:shortzz/screen/search_screen/search_screen.dart';
import 'package:shortzz/utilities/asset_res.dart';
import 'package:shortzz/utilities/text_style_custom.dart';
import 'package:shortzz/utilities/theme_res.dart';

class CustomAppBar extends StatelessWidget {
  final String title;
  final Widget? widget;
  final Widget? rowWidget;
  final String? subTitle;
  final TextStyle? titleStyle;
  final Color? bgColor;
  final Color? iconColor;
  final bool isLoading;

  /// Renders a search action in the trailing slot. On by default so search is
  /// reachable from every screen; pass false on composer/form screens where
  /// navigating away would discard input, and on screens that already carry
  /// their own search field. Ignored when [rowWidget] is supplied.
  final bool showSearch;

  const CustomAppBar(
      {super.key,
      required this.title,
      this.widget,
      this.subTitle,
      this.titleStyle,
      this.bgColor,
      this.iconColor,
      this.rowWidget,
      this.showSearch = true,
      this.isLoading = false});

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      color: bgColor ?? bgLightGrey(context),
      child: SafeArea(
        bottom: false,
        child: Column(
          spacing: widget != null ? 10 : 0,
          children: [
            const SizedBox(height: 10),
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                CustomBackButton(
                  color: iconColor,
                  width: 18,
                  height: 18,
                  padding: const EdgeInsets.all(15),
                ),
                Expanded(
                  child: Column(
                    children: [
                      Text(
                        title,
                        style: titleStyle ?? TextStyleCustom.unboundedMedium500(color: textDarkGrey(context)),
                        overflow: TextOverflow.ellipsis,
                      ),
                      if (isLoading)
                        CupertinoActivityIndicator(
                          color: textLightGrey(context),
                          radius: 8,
                        )
                      else if (subTitle != null)
                        Text(
                          subTitle ?? '',
                          style: TextStyleCustom.outFitLight300(
                            color: textLightGrey(context),
                          ),
                          overflow: TextOverflow.ellipsis,
                        ),
                    ],
                  ),
                ),
                rowWidget ??
                    (showSearch
                        ? AppBarSearchButton(iconColor: iconColor)
                        : const SizedBox(width: 48))
              ],
            ),
            widget ?? const SizedBox(height: 10),
          ],
        ),
      ),
    );
  }
}

/// Trailing search action. Sized to match the 48pt back-button slot so the
/// title stays optically centred whether or not it is shown.
class AppBarSearchButton extends StatelessWidget {
  const AppBarSearchButton({super.key, this.iconColor});

  final Color? iconColor;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: () => Get.to(() => const SearchScreen()),
      child: SizedBox(
        width: 48,
        height: 48,
        child: Center(
          child: Image.asset(
            AssetRes.icSearch,
            width: 20,
            height: 20,
            color: iconColor ?? textDarkGrey(context),
          ),
        ),
      ),
    );
  }
}

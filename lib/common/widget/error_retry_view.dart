import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:shortzz/common/widget/text_button_custom.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/utilities/text_style_custom.dart';
import 'package:shortzz/utilities/theme_res.dart';

/// Shown when a list fetch fails and there is nothing cached to fall back on.
///
/// Screens used to render their "nothing here yet" empty state in this case,
/// which reads as an empty inbox/feed rather than a failure the user can do
/// something about — and where the loading flag was never cleared, as a
/// spinner that never stopped.
class ErrorRetryView extends StatelessWidget {
  const ErrorRetryView({super.key, required this.onRetry, this.message});

  final VoidCallback onRetry;
  final String? message;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 30.0),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          spacing: 12,
          children: [
            Text(
              message ?? LKey.somethingWentWrong.tr,
              style: TextStyleCustom.unboundedSemiBold600(
                  color: textLightGrey(context), fontSize: 17),
              textAlign: TextAlign.center,
            ),
            TextButtonCustom(
                onTap: onRetry,
                title: LKey.retry.tr,
                btnHeight: 40,
                btnWidth: 140,
                fontSize: 14),
          ],
        ),
      ),
    );
  }
}

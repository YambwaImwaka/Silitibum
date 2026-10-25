import 'package:flutter/widgets.dart';

extension PageControllerExtension on PageController {
  /// [animateToPage], skipped while no PageView is attached: one still
  /// loading (a loader stands in its place) or already gone. animateToPage
  /// throws "Bad state: No element" then, which reached Crashlytics from the
  /// hashtag and messages tabs.
  Future<void> animateToPageIfAttached(int page,
      {required Duration duration, required Curve curve}) async {
    if (!hasClients) return;
    await animateToPage(page, duration: duration, curve: curve);
  }
}

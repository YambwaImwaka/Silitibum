import 'package:flutter/material.dart';
import 'package:shortzz/common/extensions/page_controller_extension.dart';
import 'package:get/get.dart';
import 'package:permission_handler/permission_handler.dart';
import 'package:shortzz/common/widget/custom_app_bar.dart';
import 'package:shortzz/common/widget/custom_tab_switcher.dart';
import 'package:shortzz/common/widget/error_retry_view.dart';
import 'package:shortzz/common/widget/load_more_widget.dart';
import 'package:shortzz/common/widget/loader_widget.dart';
import 'package:shortzz/common/widget/no_data_widget.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/model/misc/activity_notification_model.dart';
import 'package:shortzz/model/misc/admin_notification_model.dart';
import 'package:shortzz/screen/notification_screen/notification_screen_controller.dart';
import 'package:shortzz/screen/notification_screen/widget/activity_notification_page.dart';
import 'package:shortzz/screen/notification_screen/widget/system_notification_page.dart';
import 'package:shortzz/utilities/text_style_custom.dart';
import 'package:shortzz/utilities/theme_res.dart';

class NotificationScreen extends StatelessWidget {
  const NotificationScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final controller = Get.put(NotificationScreenController());
    return Scaffold(
      body: Column(
        children: [
          CustomAppBar(
              title: LKey.notifications.tr,
              titleStyle: TextStyleCustom.unboundedSemiBold600(
                  fontSize: 15, color: textDarkGrey(context)),
              widget: CustomTabSwitcher(
                  items: [(LKey.activity.tr), (LKey.system.tr)],
                  selectedIndex: controller.selectedTabIndex,
                  margin:
                      const EdgeInsets.only(left: 15, right: 15, bottom: 15),
                  onTap: (index) {
                    controller.onTabChange(index);
                    controller.pageController.animateToPageIfAttached(index,
                        duration: const Duration(milliseconds: 300),
                        curve: Curves.linear);
                  })),
          const _NotificationsOffBanner(),
          Expanded(
            child: Obx(() {
              return PageView(
                controller: controller.pageController,
                onPageChanged: controller.onTabChange,
                children: [
                  /// Activity Notifications Page
                  _NotificationListWrapper<ActivityNotification>(
                      isLoading: controller.isActivityNotification.value,
                      isEmpty: controller.activityNotifications.isEmpty,
                      error: controller.activityError.value,
                      onRetry: controller.retryActivityNotifications,
                      items: controller.activityNotifications,
                      itemBuilder: (context, data) => ActivityNotificationPage(
                          data: data, controller: controller),
                      loadMore: controller.fetchActivityNotifications),

                  /// Admin Notifications Page
                  _NotificationListWrapper<AdminNotificationData>(
                    isLoading: controller.isAdminNotification.value,
                    isEmpty: controller.adminNotifications.isEmpty,
                    error: controller.adminError.value,
                    onRetry: controller.retryAdminNotifications,
                    items: controller.adminNotifications,
                    itemBuilder: (context, data) =>
                        SystemNotificationPage(data: data),
                    loadMore: controller.fetchAdminNotification,
                  ),
                ],
              );
            }),
          )
        ],
      ),
    );
  }
}

/// Shown while the phone blocks this app's notifications — never asked on
/// Android 13+ (the launch-time camera prompt used to cancel that request),
/// denied, or switched off in system settings — so the user learns why
/// follows, gifts and messages never pinged them, and can fix it in a tap.
class _NotificationsOffBanner extends StatefulWidget {
  const _NotificationsOffBanner();

  @override
  State<_NotificationsOffBanner> createState() =>
      _NotificationsOffBannerState();
}

class _NotificationsOffBannerState extends State<_NotificationsOffBanner> {
  bool _blocked = false;
  late final AppLifecycleListener _lifecycle;

  @override
  void initState() {
    super.initState();
    _check();
    // Back from system settings: re-check.
    _lifecycle = AppLifecycleListener(onResume: _check);
  }

  @override
  void dispose() {
    _lifecycle.dispose();
    super.dispose();
  }

  Future<void> _check() async {
    final bool blocked = !(await Permission.notification.isGranted);
    if (mounted && blocked != _blocked) setState(() => _blocked = blocked);
  }

  Future<void> _turnOn() async {
    final status = await Permission.notification.request();
    if (!status.isGranted) await openAppSettings();
    await _check();
  }

  @override
  Widget build(BuildContext context) {
    if (!_blocked) return const SizedBox();
    return Container(
      margin: const EdgeInsets.fromLTRB(15, 0, 15, 10),
      padding: const EdgeInsets.fromLTRB(14, 10, 8, 10),
      decoration: BoxDecoration(
          color: bgLightGrey(context), borderRadius: BorderRadius.circular(12)),
      child: Row(
        children: [
          Icon(Icons.notifications_off_outlined, color: textDarkGrey(context)),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(LKey.notificationsOffTitle.tr,
                    style: TextStyleCustom.outFitMedium500(
                        color: textDarkGrey(context), fontSize: 15)),
                Text(LKey.notificationsOffDescription.tr,
                    style: TextStyleCustom.outFitLight300(
                        color: textLightGrey(context), fontSize: 13)),
              ],
            ),
          ),
          TextButton(
            onPressed: _turnOn,
            child: Text(LKey.turnOn.tr,
                style: TextStyleCustom.outFitMedium500(
                    color: themeAccentSolid(context), fontSize: 15)),
          ),
        ],
      ),
    );
  }
}

class _NotificationListWrapper<T> extends StatelessWidget {
  final bool isLoading;
  final bool isEmpty;
  final String? error;
  final VoidCallback onRetry;
  final List<T> items;
  final Widget Function(BuildContext, T) itemBuilder;
  final Future<void> Function() loadMore;

  const _NotificationListWrapper({
    required this.isLoading,
    required this.isEmpty,
    required this.error,
    required this.onRetry,
    required this.items,
    required this.itemBuilder,
    required this.loadMore,
  });

  @override
  Widget build(BuildContext context) {
    if (isLoading && isEmpty) {
      return const LoaderWidget();
    }

    if (error != null && isEmpty) {
      return ErrorRetryView(message: error, onRetry: onRetry);
    }

    return NoDataView(
      showShow: isEmpty,
      child: LoadMoreWidget(
        loadMore: loadMore,
        child: ListView.builder(
          padding: const EdgeInsets.only(bottom: 20),
          itemCount: items.length,
          itemBuilder: (context, index) {
            return itemBuilder(context, items[index]);
          },
        ),
      ),
    );
  }
}

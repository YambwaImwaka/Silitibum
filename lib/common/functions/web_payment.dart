import 'package:flutter/widgets.dart';
import 'package:shortzz/common/manager/logger.dart';
import 'package:shortzz/common/manager/session_manager.dart';
import 'package:shortzz/common/service/api/gift_wallet_service.dart';
import 'package:shortzz/common/service/api/user_service.dart';
import 'package:shortzz/model/user_model/user_model.dart';
import 'package:shortzz/utilities/const_res.dart';
import 'package:url_launcher/url_launcher.dart';

/// A mobile-money (Lenco) payment page on the website.
enum WebPaymentPage {
  topUp('topup'),
  blueTick('verify');

  const WebPaymentPage(this.value);

  final String value;
}

/// Opens [page] in the browser, already signed in through a single-use link:
/// the page's own password form cannot sign in accounts made with Google or
/// Apple. (Mobile-money payments live on the web, not in the app — see the
/// backend's TopUpController.) When the user comes back, the account is read
/// again so a new blue tick or coin balance shows at once; [onReturn] gets it.
Future<void> openWebPayment(WebPaymentPage page,
    {void Function(User? user)? onReturn}) async {
  String? url;
  try {
    url = await GiftWalletService.instance.createWebLoginLink(next: page.value);
  } catch (e) {
    Loggers.error('createWebLoginLink failed: $e');
  }
  // Without a link the page still works, through its own sign-in form.
  url ??= '$baseURL${page.value}';

  final bool opened =
      await launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);
  if (!opened) return;

  late final AppLifecycleListener listener;
  listener = AppLifecycleListener(onResume: () async {
    listener.dispose();
    try {
      final User? user = await UserService.instance
          .fetchUserDetails(userId: SessionManager.instance.getUserID());
      onReturn?.call(user);
    } catch (e) {
      Loggers.error('Refreshing the account after a web payment failed: $e');
    }
  });
}

import 'dart:convert';

import 'package:app_links/app_links.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:share_plus/share_plus.dart';
import 'package:shortzz/common/manager/logger.dart';
import 'package:shortzz/common/service/api/post_service.dart';
import 'package:shortzz/model/post_story/post_model.dart';
import 'package:shortzz/model/user_model/user_model.dart';
import 'package:shortzz/screen/share_sheet_widget/share_sheet_widget.dart';
import 'package:shortzz/utilities/const_res.dart';

enum ShareKeys {
  reel('reel'),
  post('post'),
  user("user");

  const ShareKeys(this.value);

  final String value;
}

class ShareManager {
  static var shared = ShareManager();
  var isListenerConfigured = false;

  void listen(Function(String key, int value) completion) {
    if (isListenerConfigured) return;
    isListenerConfigured = true;
    AppLinks().uriLinkStream.listen((uri) {
      Loggers.info('Share Link Opened: $uri ${uri.pathSegments} ${uri.path}');
      _parseShareUri(uri, completion);
    }, onError: (e) {
      // An error on this stream used to go nowhere. Unhandled, it escapes to
      // PlatformDispatcher.onError and is recorded as a fatal.
      Loggers.error('uriLinkStream error: $e');
    });
  }

  void getValuesFromURL({required String url, required Function(String key, int value) completion}) {
    _parseShareUri(Uri.tryParse(url), completion);
  }

  /// Decodes a share link and invokes [completion] only if it is genuinely
  /// well-formed.
  ///
  /// Both call sites previously did `int.parse(values.last)` straight off
  /// base64 taken from the URL. That input is fully attacker- and
  /// typo-controllable — a truncated or hand-edited silitibum.com/s/… link is
  /// enough — and every step could throw: base64.decode on invalid characters,
  /// utf8.decode on invalid bytes, and int.parse on a non-numeric tail. In
  /// listen() that throw happened inside a stream callback, so it surfaced as
  /// an uncaught async fatal rather than a link that simply did not open.
  ///
  /// A malformed link should do nothing at all, which is what this does.
  void _parseShareUri(Uri? uri, Function(String key, int value) completion) {
    if (uri == null || uri.pathSegments.isEmpty) return;

    try {
      final decoded = safeBase64Decode(uri.pathSegments.last);
      final values = decoded.split('_');
      if (values.length < 2) {
        Loggers.warning('Share link payload has no id: $decoded');
        return;
      }

      final id = int.tryParse(values.last);
      if (id == null) {
        Loggers.warning('Share link id is not numeric: ${values.last}');
        return;
      }

      completion(values.first, id);
    } catch (e) {
      Loggers.error('Could not decode share link $uri: $e');
    }
  }

  void shareTheContent({required ShareKeys key, required int value}) {
    final encoded = safeBase64Encode('${key.value}_$value');
    final url = '${baseURL}s/$encoded';

    // sharePositionOrigin only matters for the iPad popover anchor; on Android
    // it is ignored. It was computed as `box!.localToGlobal(...)`, which
    // crashes whenever the context has no RenderBox yet — so a share tap
    // during a rebuild took the app down instead of opening the sheet.
    final context = Get.context;
    final box = context?.findRenderObject() as RenderBox?;
    final origin = (box != null && box.hasSize)
        ? box.localToGlobal(Offset.zero) & box.size
        : null;

    SharePlus.instance.share(ShareParams(uri: Uri.parse(url), sharePositionOrigin: origin));
  }

  String getLink({required ShareKeys key, required int value}) {
    final encoded = safeBase64Encode('${key.value}_$value');
    final url = '${baseURL}s/$encoded';
    return url;
  }

  String safeBase64Encode(String input) {
    // Encode normally
    String encoded = base64.encode(utf8.encode(input));

    // Remove all '=' padding at the end
    return encoded.replaceAll('=', '');
  }

  String safeBase64Decode(String input) {
    // Remove all whitespace
    input = input.trim();
    Loggers.info(input);

    // Remove any invalid padding (> 2 '=' at end)
    input = input.replaceAll(RegExp(r'=+$'), '');

    // Add correct padding (base64 should be multiple of 4)
    while (input.length % 4 != 0) {
      input += '=';
    }

    return utf8.decode(base64.decode(input));
  }

  void showCustomShareSheet({
    User? user,
    Post? post,
    required ShareKeys keys,
    VoidCallback? onShareSuccess,
  }) {
    int? id = keys == ShareKeys.user ? user?.id : post?.id;
    String link = getLink(key: keys, value: id ?? -1);
    Get.bottomSheet(
      ShareSheetWidget(
        onMoreTap: () {
          Get.back();
          if (keys == ShareKeys.post || keys == ShareKeys.reel) {
            shareTheContent(key: keys, value: post?.id ?? -1);
            _increaseShareCount(post?.id, onShareSuccess);
          } else if (keys == ShareKeys.user) {
            shareTheContent(key: keys, value: user?.id ?? -1);
          }
        },
        post: post,
        link: link,
        isDownloadShow: keys == ShareKeys.reel,
        keys: keys,
        onCallBack: onShareSuccess,
      ),
      isScrollControlled: true,
    );
  }

  Future<void> _increaseShareCount(int? postId, VoidCallback? onSuccess) async {
    if (postId == null) return;
    final response = await PostService.instance.increaseShareCount(postId: postId);
    if (response.status == true) {
      onSuccess?.call();
    }
  }
}

import 'package:firebase_crashlytics/firebase_crashlytics.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:shortzz/common/manager/logger.dart';
import 'package:shortzz/common/widget/loader_widget.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/utilities/text_style_custom.dart';
import 'package:shortzz/utilities/theme_res.dart';

class BaseController extends FullLifeCycleController {
  RxBool isLoading = false.obs;
  static final share = BaseController();

  /// Runs [action] behind the modal loader and guarantees the loader is
  /// dismissed on every path — success or thrown error. Returns null on
  /// failure (after logging, Crashlytics, and a user-facing snackbar), so
  /// callers branch on the result instead of writing their own try/catch.
  ///
  /// The barrier is non-dismissible: stopLoader() blindly pops the top
  /// dialog, so letting the user close the barrier manually would make the
  /// later pop remove whatever is beneath it.
  Future<T?> withLoader<T>(Future<T> Function() action,
      {String? errorMessage}) async {
    // Re-entrant calls (an action that itself uses withLoader on the same
    // controller) must not pop the dialog they didn't open.
    final ownsLoader = !isLoading.value;
    if (ownsLoader) {
      showLoader(barrierDismissible: false);
    }
    try {
      return await action();
    } catch (e, st) {
      Loggers.error('withLoader: $e');
      FirebaseCrashlytics.instance.recordError(e, st, fatal: false);
      showSnackBar(errorMessage ?? LKey.somethingWentWrong.tr);
      return null;
    } finally {
      if (ownsLoader) stopLoader();
    }
  }

  void showLoader({bool barrierDismissible = true}) async {
    if (isLoading.value) return;
    if (Get.isSnackbarOpen) {
      Get.back();
    }
    isLoading.value = true;
    try {
      await Get.dialog(const LoaderWidget(),
          barrierDismissible: barrierDismissible);
    } finally {
      // Guarantees isLoading resets even if Get.dialog() throws (e.g. no
      // Material context available) — without this, an exception here left
      // isLoading stuck at true forever with no dialog to dismiss it, which
      // reads to the user as the button being permanently frozen.
      isLoading.value = false;
    }
  }

  void stopLoader() {
    if (Get.isDialogOpen == true) {
      Get.back();
    }
  }

  void showSnackBar(String? title, {int second = 2}) {
    if (Get.isSnackbarOpen) {
      return;
    }

    Get.rawSnackbar(
      backgroundColor: blackPure(Get.context!),
      margin: const EdgeInsets.symmetric(horizontal: 10),
      padding: const EdgeInsets.all(15),
      borderRadius: 10,
      isDismissible: true,
      duration: Duration(seconds: second),
      snackPosition: SnackPosition.TOP,
      messageText: Text(title?.capitalizeFirst?.tr ?? '',
          style: TextStyleCustom.outFitRegular400(
              color: whitePure(Get.context!), fontSize: 17)),
    );
  }

  void stopSnackBar() {
    if (Get.isSnackbarOpen) {
      Get.back();
    }
  }
}

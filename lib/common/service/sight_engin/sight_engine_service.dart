import 'dart:convert';
import 'dart:developer';

import 'package:get/get.dart';
import 'package:http/http.dart' as http;
import 'package:image_picker/image_picker.dart';
import 'package:shortzz/common/controller/base_controller.dart';
import 'package:shortzz/common/manager/logger.dart';
import 'package:shortzz/common/manager/session_manager.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/model/sight_engine/sight_engine_media_model.dart';
import 'package:shortzz/model/sight_engine/text_moderation_model.dart';

class SightEngineService {
  static var shared = SightEngineService();

  static const Duration _requestTimeout = Duration(seconds: 60);

  bool get _isModerationOff =>
      SessionManager.instance.getSettings()?.isContentModeration == 0;

  String get _apiUser =>
      (SessionManager.instance.getSettings()?.sightEngineApiUser ?? '').trim();

  String get _apiSecret =>
      (SessionManager.instance.getSettings()?.sightEngineApiSecret ?? '')
          .trim();

  /// Fail closed: moderation is intentionally ON, so when the SightEngine
  /// credentials are missing from the admin panel we block the post with a
  /// clear message instead of sending the request with empty keys (whose raw
  /// "invalid api_user or api_secret" error used to surface to users) or
  /// silently skipping the check.
  bool _ensureConfigured({String? workflowId}) {
    final missing = _apiUser.isEmpty ||
        _apiSecret.isEmpty ||
        (workflowId != null && workflowId.isEmpty);
    if (missing) {
      Loggers.error(
          'SightEngine credentials/workflow missing — fill them in admin settings or disable content moderation');
      BaseController.share.showSnackBar(LKey.moderationNotConfigured.tr);
      return false;
    }
    return true;
  }

  Future<void> checkImagesInSightEngine({
    required List<XFile> xFiles,
    required Function() completion,
  }) async {
    if (_isModerationOff) {
      completion();
      return;
    }
    final workflow =
        (SessionManager.instance.getSettings()?.sightEngineImageWorkflowId ??
                '')
            .trim();
    if (!_ensureConfigured(workflowId: workflow)) return;

    final String? action =
        await BaseController.share.withLoader<String?>(() async {
      var request = http.MultipartRequest('POST',
          Uri.parse('https://api.sightengine.com/1.0/check-workflow.json'));

      request.fields['workflow'] = workflow;
      request.fields['api_user'] = _apiUser;
      request.fields['api_secret'] = _apiSecret;

      for (XFile xFile in xFiles) {
        request.files.add(await http.MultipartFile.fromPath(
            'media', xFile.path,
            filename: xFile.path.split("/").last));
      }

      var response = await request.send().timeout(_requestTimeout);
      var respStr = await response.stream.bytesToString();
      SightEngineMediaModel sightEngineMediaModel =
          SightEngineMediaModel.fromJson(jsonDecode(respStr));

      if (sightEngineMediaModel.error != null) {
        Loggers.error(sightEngineMediaModel.error?.message);
        BaseController.share
            .showSnackBar(sightEngineMediaModel.error?.message ?? '');
        return null;
      }

      var result = sightEngineMediaModel.summary?.action ?? '';
      if (result == 'reject') {
        var summaryDescription = sightEngineMediaModel.summary?.rejectReason
                ?.map((e) => e.text ?? '')
                .join(', ') ??
            '';
        BaseController.share.showSnackBar(
            '${LKey.mediaRejectedAndContainsSuchThings.tr} $summaryDescription');
      }
      return result;
    });

    if (action == 'accept') {
      completion(); // All images accepted
    }
  }

  // NOTE: there is deliberately no video moderation here.
  //
  // Video Analysis is a paid-plan SightEngine feature. On this account every
  // call to video/check-workflow-sync.json came back
  // `usage_limit` / code 3701 ("You do not have access to Video Analysis"),
  // which the caller could not distinguish from a real verdict — the upload
  // callback simply never fired, so reels, video stories and video feed posts
  // silently refused to upload while photos still worked.
  //
  // Videos are now published unchecked and reviewed by hand: an admin removes
  // explicit uploads from the dashboard (Posts → delete, and the moderator
  // endpoints in ModeratorController). Image and text moderation below are
  // unaffected — those work on the free plan and still run on every post.
  // If a video-capable provider is added later, re-introduce the gate here so
  // the call sites keep their single moderation entry point.

  Future<void> chooseTextModeration(
      {required String text, required Function() completion}) async {
    if (_isModerationOff || text.isEmpty) {
      completion();
      return;
    }
    if (!_ensureConfigured()) return;

    final bool? isClean = await BaseController.share.withLoader<bool?>(() async {
      var request = http.MultipartRequest(
          'POST', Uri.parse('https://api.sightengine.com/1.0/text/check.json'));
      request.fields.addAll({
        'text': text,
        'lang': 'en,zh,da,nl,fi,fr,de,it,no,pl,pt,es,sv,tl,tr',
        'categories': 'profanity',
        'mode': 'rules',
        'api_user': _apiUser,
        'api_secret': _apiSecret,
      });

      http.StreamedResponse response =
          await request.send().timeout(_requestTimeout);

      if (response.statusCode != 200) {
        Loggers.error(
            'SightEngine text check failed: ${response.statusCode} ${response.reasonPhrase}');
        BaseController.share.showSnackBar(LKey.somethingWentWrong.tr);
        return null;
      }

      var respStr = await response.stream.bytesToString();
      TextModerationModel textModerationModel =
          TextModerationModel.fromJson(jsonDecode(respStr));
      if (textModerationModel.error != null) {
        BaseController.share
            .showSnackBar(textModerationModel.error?.message ?? '');
        return null;
      }

      List<Matches> matches = textModerationModel.profanity?.matches ?? [];
      List<String> words = [];
      for (var element in matches) {
        if (element.intensity == 'high' || element.intensity == 'medium') {
          words.add(element.match ?? '');
        }
      }

      if (words.isNotEmpty) {
        log('${LKey.textRejectedAndContainsSuchThings.tr} ${words.join(', ')}');
        BaseController.share.showSnackBar(
            '${LKey.textRejectedAndContainsSuchThings.tr} ${words.join(', ')}');
        return false;
      }
      return true;
    });

    if (isClean == true) {
      completion();
    }
  }
}

import 'dart:convert';
import 'dart:developer';
import 'dart:io';

import 'package:flutter_native_video_trimmer/flutter_native_video_trimmer.dart';
import 'package:get/get.dart';
import 'package:http/http.dart' as http;
import 'package:image_picker/image_picker.dart';
import 'package:shortzz/common/controller/base_controller.dart';
import 'package:shortzz/common/manager/logger.dart';
import 'package:shortzz/common/manager/session_manager.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/model/sight_engine/sight_engine_media_model.dart';
import 'package:shortzz/model/sight_engine/text_moderation_model.dart';
import 'package:shortzz/utilities/app_res.dart';

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

  Future<void> checkVideoInSightEngine(
      {required XFile xFile,
      required int duration,
      required Function() completion}) async {
    if (_isModerationOff) {
      completion();
      return;
    }
    final workflow =
        (SessionManager.instance.getSettings()?.sightEngineVideoWorkflowId ??
                '')
            .trim();
    if (!_ensureConfigured(workflowId: workflow)) return;

    final String? action =
        await BaseController.share.withLoader<String?>(() async {
      File file = File(xFile.path);
      if (duration > AppRes.sightEngineCropSec) {
        final videoTrimmer = VideoTrimmer();
        await videoTrimmer.loadVideo(file.path);
        final trimmedPath = await videoTrimmer.trimVideo(
          startTimeMs: 0,
          endTimeMs: AppRes.sightEngineCropSec * 1000,
          includeAudio: false,
        );
        file = File(trimmedPath ?? '');
      }

      var request = http.MultipartRequest(
        'POST',
        Uri.parse(
            'https://api.sightengine.com/1.0/video/check-workflow-sync.json'),
      );
      request.fields['workflow'] = workflow;
      request.fields['api_user'] = _apiUser;
      request.fields['api_secret'] = _apiSecret;

      request.files.add(await http.MultipartFile.fromPath('media', file.path,
          filename: file.path.split("/").last));

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
      completion();
    }
  }

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

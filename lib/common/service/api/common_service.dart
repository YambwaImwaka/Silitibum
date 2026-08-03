import 'dart:convert';

import 'package:http/http.dart';
import 'package:image_picker/image_picker.dart';
import 'package:shortzz/common/manager/logger.dart';
import 'package:shortzz/common/manager/session_manager.dart';
import 'package:shortzz/common/service/api/api_service.dart';
import 'package:shortzz/common/service/utils/params.dart';
import 'package:shortzz/common/service/utils/web_service.dart';
import 'package:shortzz/model/general/file_path_model.dart';
import 'package:shortzz/model/general/location_place_model.dart';
import 'package:shortzz/model/general/place_detail.dart';
import 'package:shortzz/model/general/settings_model.dart';
import 'package:shortzz/model/general/status_model.dart';
import 'package:shortzz/utilities/app_res.dart';

class CommonService {
  CommonService._();

  static final CommonService instance = CommonService._();

  Future<bool> fetchGlobalSettings() async {
    SettingModel settingsModel = await ApiService.instance.call(
        url: WebService.setting.fetchSettings,
        // Skips privacy_policy + terms_of_uses, which were 79% of this
        // payload (26KB -> 5KB) and are only needed on the privacy/terms
        // screen — fetchLegalDocs() loads them there instead. This request
        // is on the cold-start critical path, so the bytes matter.
        param: {Params.lite: '1'},
        fromJson: SettingModel.fromJson,
        cancelAuthToken: true);

    var setting = settingsModel.data;
    if (setting != null) {
      SessionManager.instance.setSettings(setting);
      return true;
    }
    return false;
  }

  /// The two large HTML documents split out of [fetchGlobalSettings]. Returns
  /// null on failure so the caller can show its own error state rather than
  /// an empty page that looks like missing content.
  Future<LegalDocs?> fetchLegalDocs() async {
    try {
      final json = await ApiService.instance.call<Map<String, dynamic>>(
          url: WebService.setting.fetchLegalDocs, cancelAuthToken: true);
      final data = json['data'];
      if (data is Map<String, dynamic>) return LegalDocs.fromJson(data);
    } catch (e) {
      Loggers.error('fetchLegalDocs failed: $e');
    }
    return null;
  }

  Future<FilePathModel> uploadFileGivePath(XFile files,
      {Function(double percentage)? onProgress}) async {
    FilePathModel model = await ApiService.instance.multiPartCallApi(
      url: WebService.setting.uploadFileGivePath,
      filesMap: {
        Params.file: [files]
      },
      onProgress: onProgress,
      fromJson: FilePathModel.fromJson,
    );

    return model;
  }

  Future<StatusModel> deleteFile(String filePath) async {
    StatusModel model = await ApiService.instance.call(
        url: WebService.setting.deleteFile,
        param: {Params.filePath: filePath},
        fromJson: StatusModel.fromJson);
    return model;
  }

  Future<List<Places>> searchPlace({String title = ''}) async {
    Setting? settings = SessionManager.instance.getSettings();

    Map<String, String> header = {
      Params.authorization:
          'Bearer ${settings?.placeApiAccessToken ?? 'PLACE API ACCESS TOKEN EMPTY'}'
    };

    Map<String, dynamic> body = {
      Params.textQuery: title,
      Params.maxResultCount: '${AppRes.paginationLimit}'
    };

    Uri uri = Uri.parse(WebService.google.searchTextByPlace);

    Loggers.info(uri);
    Loggers.info(header);
    Loggers.info(body);

    Response response = await post(uri, headers: header, body: body);
    LocationPlaceModel model =
        LocationPlaceModel.fromJson(jsonDecode(response.body));

    Loggers.error(model.error?.toJson() ?? 'NO ERROR');
    Loggers.success(model.places?.map((e) => e.toJson()));

    return model.places ?? [];
  }

  Future<List<Places>> searchNearBy(
      {required double lat, required double lon}) async {
    Setting? settings = SessionManager.instance.getSettings();

    Map<String, String> header = {
      Params.authorization:
          'Bearer ${settings?.placeApiAccessToken ?? 'PLACE API ACCESS TOKEN EMPTY'}'
    };
    Map<String, dynamic> locationRestriction = {
      Params.circle: {
        Params.center: {Params.latitude: '$lat', Params.longitude: '$lon'},
        Params.radius: '${AppRes.nearBySearchRadius}'
      }
    };
    Map<String, dynamic> body = {
      Params.includedTypes: AppRes.nearbySearchTypes,
      Params.maxResultCount: AppRes.paginationLimit.toString(),
      Params.locationRestriction: locationRestriction
    };

    Uri uri = Uri.parse(WebService.google.searchNearByPlace(lat, lon));

    Loggers.info('URI : $uri');
    Loggers.info('HEADER : $header');
    Loggers.info('BODY : $body');
    Response response =
        await post(uri, headers: header, body: jsonEncode(body));
    LocationPlaceModel model =
        LocationPlaceModel.fromJson(jsonDecode(response.body));

    Loggers.error(model.error?.toJson() ?? 'NO ERROR');
    Loggers.success(model.places?.map((e) => e.toJson()));

    return model.places ?? [];
  }

  Future<PlaceDetail> getIPPlaceDetail() async {
    Map<String, dynamic> detail =
        await ApiService.instance.callGet(url: WebService.common.ipApi);
    return PlaceDetail.fromJson(detail);
  }
}

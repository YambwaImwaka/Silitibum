import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:get/get.dart';
import 'package:http/http.dart' as http;
import 'package:image_picker/image_picker.dart';
import 'package:shortzz/common/functions/debounce_action.dart';
import 'package:shortzz/common/manager/logger.dart';
import 'package:shortzz/common/manager/session_manager.dart';
import 'package:shortzz/common/service/utils/params.dart';
import 'package:shortzz/screen/session_expired_screen/session_expired_screen.dart';
import 'package:shortzz/utilities/const_res.dart';

class CancelToken {
  bool _isCancelled = false;

  bool get isCancelled => _isCancelled;

  void cancel() {
    _isCancelled = true;
  }

  void dispose() {
    _isCancelled = false;
  }
}

class ApiService {
  ApiService._();

  static final ApiService instance = ApiService._();

  // One persistent client for all requests: reuses TCP+TLS connections
  // (keep-alive) instead of paying a fresh handshake per API call. Note that
  // CancelToken.cancel() only marks the result as ignorable — it never closed
  // the socket mid-flight even before this change — so sharing the client
  // does not alter cancellation behavior.
  static final http.Client _sharedClient = http.Client();

  // Fail fast instead of letting a request hang forever on a dead connection;
  // callers already treat exceptions as network failures.
  static const Duration _requestTimeout = Duration(seconds: 30);

  // Headers are built per call: a shared mutable map leaks the AUTHTOKEN of a
  // previous call into `cancelAuthToken` requests and races across concurrent
  // calls. Guests (no session) send the apikey only.
  Map<String, String> _buildHeaders({bool includeAuth = true}) {
    final headers = {Params.apikey: apiKey};
    if (includeAuth && SessionManager.instance.isLogin()) {
      headers[Params.authToken] = SessionManager.instance.getAuthToken();
    }
    return headers;
  }

  Future<T> call<T>({
    required String url,
    Map<String, dynamic>? param,
    CancelToken? cancelToken,
    bool cancelAuthToken = false,
    T Function(Map<String, dynamic> json)? fromJson,
    Function()? onError,
  }) async {
    Map<String, String> params = {};
    param?.removeWhere(
        (key, value) => value == null || value == 'null' || value == '');
    param?.forEach((key, value) {
      params[key] = "$value";
    });

    final headers = _buildHeaders(includeAuth: !cancelAuthToken);
    Loggers.info("URL: $url");
    Loggers.info("header: $headers");
    Loggers.info("Parameters: ${params.isEmpty ? "Empty" : params}");
    try {
      final response = await _sharedClient
          .post(Uri.parse(url), headers: headers, body: params)
          .timeout(_requestTimeout);
      Loggers.success(response.statusCode);
      if (cancelToken?.isCancelled ?? false) {
        if (kDebugMode) {
          print("Request cancelled: $url");
        }
        throw Exception('Request was cancelled');
      }

      if (response.statusCode >= 200 && response.statusCode < 300) {
        final decodedResponse = _decodeJsonBody(response.body, url);

        if (decodedResponse['message'] == 'this user is freezed!') {
          DebounceAction.shared.call(() {
            Get.offAll(
                () => const SessionExpiredScreen(type: SessionType.freeze));
          });
          return decodedResponse as T;
        }

        if (decodedResponse['status'] == false) {
          Loggers.error('API RESPONSE : ${decodedResponse['message']}');
          onError?.call();
        }

        if (kDebugMode) {
          // Re-encoding the whole response is expensive; debug builds only.
          var prettyString =
              const JsonEncoder.withIndent('  ').convert(decodedResponse);
          Loggers.info(prettyString);
        }

        // Use the provided `fromJson` function to parse the response
        if (fromJson != null) {
          return fromJson(decodedResponse);
        }

        // If no `fromJson` is provided, return the raw response
        return decodedResponse as T;
      } else if (response.statusCode == 401) {
        Loggers.error('Unauthorized Error 401: ${response.statusCode}');
        // Only a logged-in user has a session that can expire. A guest hitting
        // an auth-only endpoint just gets the error; callers show empty states.
        if (SessionManager.instance.isLogin()) {
          DebounceAction.shared.call(() {
            Get.offAll(() =>
                const SessionExpiredScreen(type: SessionType.unauthorized));
          });
        }
        throw Exception("Unauthorized Error: ${response.statusCode}");
      } else if (response.statusCode == 404) {
        Loggers.error('Please check baseURL in const.dart file');
        throw Exception("URL Error: ${response.statusCode} - $url");
      } else {
        final errorBody = response.body;
        final errorMessage = _extractErrorMessage(errorBody);
        Loggers.error('HTTP Error: $errorMessage');
        // Handle HTTP errors
        throw Exception(
            "HTTP Error: ${response.statusCode} - ${response.reasonPhrase}");
      }
    } on TimeoutException {
      Loggers.error("Request timed out: $url");
      throw Exception('Request timed out');
    } on HttpException {
      throw Exception('Could not connect to the server');
    } on FormatException catch (e) {
      // Handle JSON decoding errors
      Loggers.error("Invalid JSON format: ${e.message}");
      throw Exception("Invalid JSON format: ${e.message}");
    } on Exception catch (e) {
      Loggers.error("Unexpected error : $e");
      rethrow;
    }
  }

  /// Some hosts echo a stray PHP warning/notice ahead of the JSON body on an
  /// otherwise-successful (HTTP 200) response — the request fully succeeded
  /// server-side (e.g. an account really was created), but a naive
  /// `jsonDecode` still throws a FormatException here, and every caller then
  /// shows a generic failure even though nothing actually went wrong. Fall
  /// back to decoding from the first '{' so a leading warning doesn't hide a
  /// real success; log the discarded prefix so the underlying PHP issue is
  /// still visible for debugging.
  Map<String, dynamic> _decodeJsonBody(String body, String url) {
    try {
      return jsonDecode(body) as Map<String, dynamic>;
    } on FormatException {
      final start = body.indexOf('{');
      if (start > 0) {
        Loggers.error(
            'Non-JSON prefix before response body for $url: ${_shorten(body.substring(0, start))}');
        try {
          return jsonDecode(body.substring(start)) as Map<String, dynamic>;
        } on FormatException {
          // fall through to rethrow the original error below
        }
      }
      rethrow;
    }
  }

  String _extractErrorMessage(String responseBody) {
    final regex = RegExp(
      r'<!--\s*(.*?)\s*#0 ', // Matches everything between <!-- and #0
      dotAll: true,
    );
    final match = regex.firstMatch(responseBody);
    return match?.group(1)?.trim() ??
        "Unknown error occurred: ${_shorten(responseBody)}";
  }

  /// Shortens the response body if no specific error is found
  String _shorten(String responseBody) {
    const maxLength = 100;
    return responseBody.length > maxLength
        ? "${responseBody.substring(0, maxLength)}..."
        : responseBody;
  }

  Future<T> callGet<T>({required String url}) async {
    http.Response response =
        await _sharedClient.get(Uri.parse(url)).timeout(_requestTimeout);
    return jsonDecode(response.body);
  }

  Future<T> multiPartCallApi<T>({
    required String url,
    Map<String, dynamic>? param,
    required Map<String, List<XFile?>> filesMap,
    Function(double percentage)? onProgress,
    CancelToken? cancelToken,
    T Function(Map<String, dynamic> json)? fromJson,
  }) async {
    final request = MultipartRequest(
      'POST',
      Uri.parse(url),
      onProgress: (bytes, totalBytes) {
        if (onProgress != null) {
          onProgress(bytes / totalBytes);
        }
      },
    );

    Map<String, String> params = {};
    param?.removeWhere((key, value) => value == null || value == 'null');
    param?.forEach((key, value) {
      params[key] = "$value";
    });

    request.fields.addAll(params);
    // Uploads are authenticated actions; without this the multipart path only
    // worked because call() used to leak the token into a shared header map.
    request.headers.addAll(_buildHeaders());

    filesMap.forEach((keyName, files) {
      for (var xFile in files) {
        if (xFile != null && xFile.path.isNotEmpty) {
          final file = File(xFile.path);
          final multipartFile = http.MultipartFile(
              keyName, file.readAsBytes().asStream(), file.lengthSync(),
              filename: xFile.name);
          request.files.add(multipartFile);
        }
      }
    });
    Loggers.info("URL : $url");
    Loggers.info("HEADERS : ${request.headers}");
    Loggers.info("FIELDS : ${request.fields}");
    Loggers.info("FILES : ${request.files.map((e) => e)}");

    // No timeout here: large uploads legitimately take longer than any fixed
    // request timeout; progress callbacks give the UI liveness instead.
    final responseStream = await _sharedClient.send(request);

    if (cancelToken?.isCancelled ?? false) {
      if (kDebugMode) {
        Loggers.error("Request cancelled: $url");
      }
      throw Exception('Request was cancelled');
    }

    final responseStr = await responseStream.stream.bytesToString();
    final decodedResponse = jsonDecode(responseStr) as Map<String, dynamic>;

    if (decodedResponse['status'] == false) {
      Loggers.error(decodedResponse['message']);
    }
    // Use the provided `fromJson` function to parse the response
    if (fromJson != null) {
      return fromJson(decodedResponse);
    }

    // If no `fromJson` is provided, return the raw response
    return decodedResponse as T;
  }

  Future<void> useAndDeleteFile(File file) async {
    try {
      // Use the file as needed
      Loggers.warning('File path: ${file.path}');

      // Delete the file after use
      if (await file.exists()) {
        await file.delete();
        Loggers.success('File deleted from: ${file.path}');
      }
    } catch (e) {
      Loggers.error('Error: $e');
    }
  }
}

class MultipartRequest extends http.MultipartRequest {
  MultipartRequest(
    super.method,
    super.url, {
    this.onProgress,
  });

  final void Function(int bytes, int totalBytes)? onProgress;

  @override
  http.ByteStream finalize() {
    final byteStream = super.finalize();
    final total = contentLength;
    int bytes = 0;

    final transformer = StreamTransformer<List<int>, List<int>>.fromHandlers(
      handleData: (data, sink) {
        bytes += data.length;
        if (onProgress != null) {
          onProgress!(bytes, total);
        }
        sink.add(data);
      },
    );

    return http.ByteStream(byteStream.transform(transformer));
  }
}

// Live auth integration test: drives the REAL production API with the REAL
// app models — the exact parse path the login/registration screens use. This
// is the app→backend simulation that proves a Flutter request round-trips
// cleanly (HTTP → JSON → UserModel.fromJson) without "Something went wrong".
//
// Deliberately skipped by default (it creates throwaway accounts on the live
// backend). Run explicitly with:
//   flutter test test/live_auth_api_test.dart --dart-define=LIVE=true
// Retries below can span several 30s HTTP attempts plus backoff, which
// overruns package:test's default 30s per-test budget and kills the test
// mid-retry. Give each test room for the full retry ladder.
@Timeout(Duration(minutes: 5))
library;

import 'dart:async';
import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:shortzz/model/user_model/user_model.dart';
import 'package:shortzz/utilities/const_res.dart';

const bool live = bool.fromEnvironment('LIVE');

// The network path from this dev machine to the production host is unstable:
// latency swings from ~2s to >15s and a share of connections die with a
// Windows-level SocketException (errno 121) before any HTTP response exists.
// Those are client-side transport failures, not backend faults, so retry them
// rather than reporting a red test. A non-200 or a bad body is NOT retried —
// that is a real server answer and must fail loudly.
Future<UserModel> callAuth(String endpoint, Map<String, String> body) async {
  const attempts = 4;
  for (var attempt = 1;; attempt++) {
    try {
      final response = await http
          .post(Uri.parse('${apiURL}user/$endpoint'),
              headers: {'apikey': apiKey}, body: body)
          .timeout(const Duration(seconds: 30));
      expect(response.statusCode, 200,
          reason: '$endpoint returned HTTP ${response.statusCode}: '
              '${response.body.substring(0, response.body.length.clamp(0, 300))}');
      // The same parse the app performs in ApiService.call → UserModel.fromJson.
      return UserModel.fromJson(jsonDecode(response.body));
    } on http.ClientException catch (e) {
      if (attempt == attempts) rethrow;
      printOnFailure('$endpoint transport error on attempt $attempt: $e');
      await Future<void>.delayed(Duration(seconds: 2 * attempt));
    } on TimeoutException catch (e) {
      if (attempt == attempts) rethrow;
      printOnFailure('$endpoint timed out on attempt $attempt: $e');
      await Future<void>.delayed(Duration(seconds: 2 * attempt));
    }
  }
}

void main() {
  final stamp = DateTime.now().millisecondsSinceEpoch;
  final emailIdentity = 'claude.livetest.$stamp@example.com';
  // Must stay unique per run. '+26097$stamp'.substring(0, 13) kept only the
  // LEADING 7 digits of the epoch-ms, which change roughly every 17 minutes —
  // so two runs in the same window reused one number and the second failed
  // with account_exists. Take the trailing 7 digits instead; they move every
  // millisecond.
  final phoneIdentity =
      '+26097${(stamp % 10000000).toString().padLeft(7, '0')}';
  const password = 'LiveTest123';

  test('email registration parses through real UserModel', () async {
    final model = await callAuth('registerUser', {
      'identity': emailIdentity,
      'password': password,
      'login_method': 'email',
      'device': '1',
      'fullname': 'Live Test',
    });
    expect(model.status, true, reason: 'message: ${model.message}');
    expect(model.data, isNotNull);
    expect(model.data?.token?.authToken, isNotNull,
        reason: 'auth token missing — session cannot be stored');
    expect(model.data?.id, isNotNull);
  }, skip: live ? false : 'live API test — run with --dart-define=LIVE=true');

  test('email login parses through real UserModel', () async {
    final model = await callAuth('logInUser', {
      'identity': emailIdentity,
      'password': password,
      'login_method': 'email',
      'device': '1',
    });
    expect(model.status, true, reason: 'message: ${model.message}');
    expect(model.data?.token?.authToken, isNotNull);
  }, skip: live ? false : 'live API test — run with --dart-define=LIVE=true');

  test('phone registration + login parse through real UserModel', () async {
    final reg = await callAuth('registerUser', {
      'identity': phoneIdentity,
      'password': password,
      'login_method': 'phone',
      'device': '1',
      'fullname': 'Live Phone Test',
    });
    expect(reg.status, true, reason: 'message: ${reg.message}');
    expect(reg.data?.token?.authToken, isNotNull);

    final login = await callAuth('logInUser', {
      'identity': phoneIdentity,
      'password': password,
      'login_method': 'phone',
      'device': '1',
    });
    expect(login.status, true, reason: 'message: ${login.message}');
    expect(login.data?.token?.authToken, isNotNull);
  }, skip: live ? false : 'live API test — run with --dart-define=LIVE=true');

  test('wrong password returns clean failure token (no crash)', () async {
    final model = await callAuth('logInUser', {
      'identity': emailIdentity,
      'password': 'WrongPassword999',
      'login_method': 'email',
      'device': '1',
    });
    expect(model.status, false);
    expect(model.message, 'incorrect_password');
  }, skip: live ? false : 'live API test — run with --dart-define=LIVE=true');
}

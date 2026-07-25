// Live auth integration test: drives the REAL production API with the REAL
// app models — the exact parse path the login/registration screens use. This
// is the app→backend simulation that proves a Flutter request round-trips
// cleanly (HTTP → JSON → UserModel.fromJson) without "Something went wrong".
//
// Deliberately skipped by default (it creates throwaway accounts on the live
// backend). Run explicitly with:
//   flutter test test/live_auth_api_test.dart --dart-define=LIVE=true
import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:shortzz/model/user_model/user_model.dart';
import 'package:shortzz/utilities/const_res.dart';

const bool live = bool.fromEnvironment('LIVE');

Future<UserModel> callAuth(String endpoint, Map<String, String> body) async {
  final response = await http
      .post(Uri.parse('${apiURL}user/$endpoint'),
          headers: {'apikey': apiKey}, body: body)
      .timeout(const Duration(seconds: 30));
  expect(response.statusCode, 200,
      reason: '$endpoint returned HTTP ${response.statusCode}: '
          '${response.body.substring(0, response.body.length.clamp(0, 300))}');
  // The same parse the app performs in ApiService.call → UserModel.fromJson.
  return UserModel.fromJson(jsonDecode(response.body));
}

void main() {
  final stamp = DateTime.now().millisecondsSinceEpoch;
  final emailIdentity = 'claude.livetest.$stamp@example.com';
  final phoneIdentity = '+26097$stamp'.substring(0, 13);
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

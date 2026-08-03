// Live settings integration test: fetches the REAL production settings and
// parses them through the REAL app model — the exact path the splash screen
// uses. This is what guards the "PDO stringifies numbers → Setting.fromJson
// throws → whole session runs with null settings" regression against the
// actual server response.
//
// Read-only against production; still skipped by default for symmetry with
// the other live tests. Run explicitly with:
//   flutter test test/live_settings_api_test.dart --dart-define=LIVE=true
import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:shortzz/model/general/settings_model.dart';
import 'package:shortzz/utilities/const_res.dart';

const bool live = bool.fromEnvironment('LIVE');

void main() {
  test('production fetchSettings parses through real SettingModel', () async {
    final response = await http.post(
        Uri.parse('${apiURL}settings/fetchSettings'),
        headers: {'apikey': apiKey}).timeout(const Duration(seconds: 30));
    expect(response.statusCode, 200,
        reason: 'fetchSettings returned HTTP ${response.statusCode}');

    // The same parse the app performs in CommonService.fetchGlobalSettings.
    final model = SettingModel.fromJson(jsonDecode(response.body));
    expect(model.status, true, reason: 'message: ${model.message}');

    final data = model.data;
    expect(data, isNotNull);
    // The fields every reported symptom hinged on:
    expect(data!.coinValue, isNotNull, reason: 'coin_value failed to coerce');
    expect(data.zegoAppId, isNotEmpty,
        reason: 'Zego App ID missing — go-live cannot start');
    expect(data.zegoAppSign, isNotEmpty,
        reason: 'Zego App Sign missing — go-live cannot start');
    expect(data.isContentModeration, isNotNull);
    expect(data.privacyPolicy, isNotEmpty,
        reason: 'privacy policy content missing');
    expect(data.termsOfUses, isNotEmpty, reason: 'terms content missing');
    // Coin packages are admin-managed data, not a parse concern — report
    // rather than fail when none are configured yet (verified empty on
    // 2026-07-25: the wallet stays empty until packages are created in the
    // admin panel).
    if (data.coinPackages?.isEmpty ?? true) {
      // ignore: avoid_print
      print('WARNING: no active coin packages configured on the server — '
          'the coin wallet will show its empty state.');
    }
  }, skip: live ? false : 'live API test — run with --dart-define=LIVE=true');

  // The test above covers the legacy full payload (what already-installed
  // builds still request). This covers what the app actually sends now.
  test('production fetchSettings lite=1 drops legal text but keeps everything '
      'the splash screen needs', () async {
    final response = await http.post(Uri.parse('${apiURL}settings/fetchSettings'),
        headers: {'apikey': apiKey},
        body: {'lite': '1'}).timeout(const Duration(seconds: 30));
    expect(response.statusCode, 200);

    final model = SettingModel.fromJson(jsonDecode(response.body));
    expect(model.status, true, reason: 'message: ${model.message}');
    final data = model.data;
    expect(data, isNotNull);

    // The whole point of the flag: these two are ~79% of the payload bytes.
    expect(data!.privacyPolicy ?? '', isEmpty,
        reason: 'lite=1 must not ship privacy_policy');
    expect(data.termsOfUses ?? '', isEmpty,
        reason: 'lite=1 must not ship terms_of_uses');

    // Everything the cold-start path reads must survive the trim. itemBaseUrl
    // is the one that would fail silently — every image/video URL in the app
    // is built by prepending it, so an empty value degrades into broken media
    // rather than an obvious crash.
    expect(data.itemBaseUrl ?? '', isNotEmpty,
        reason: 'itemBaseUrl missing — all media URLs would break');
    expect(data.zegoAppId, isNotEmpty);
    expect(data.zegoAppSign, isNotEmpty);
    expect(data.coinValue, isNotNull);
    expect(data.isContentModeration, isNotNull);
    // Splash blocks on this list to download translation CSVs.
    expect(data.languages, isNotNull);

    expect(response.bodyBytes.length, lessThan(12000),
        reason: 'lite payload unexpectedly large — legal text may have crept '
            'back in (full payload is ~26KB)');
  }, skip: live ? false : 'live API test — run with --dart-define=LIVE=true');

  test('production fetchLegalDocs serves the documents split out of settings',
      () async {
    final response = await http.post(
        Uri.parse('${apiURL}settings/fetchLegalDocs'),
        headers: {'apikey': apiKey}).timeout(const Duration(seconds: 30));
    expect(response.statusCode, 200,
        reason: 'fetchLegalDocs returned HTTP ${response.statusCode}');

    final json = jsonDecode(response.body) as Map<String, dynamic>;
    expect(json['status'], true);
    final docs = LegalDocs.fromJson(json['data'] as Map<String, dynamic>);

    // If these are empty the privacy/terms screen shows its retry state, and
    // an app store review can fail on a missing privacy policy.
    expect(docs.privacyPolicy ?? '', isNotEmpty);
    expect(docs.termsOfUses ?? '', isNotEmpty);
  }, skip: live ? false : 'live API test — run with --dart-define=LIVE=true');
}

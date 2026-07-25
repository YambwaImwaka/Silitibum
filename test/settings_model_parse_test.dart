// Guards the exact regression that broke the whole app: production serves
// every numeric column as a JSON *string* ("coin_value":"0.05"), and an
// untolerant Setting.fromJson threw, leaving the session with null settings —
// which cascaded into "Local View not found" (Zego creds never loaded),
// posting blocked by unconfigured moderation, blank privacy policy, and an
// empty coin wallet. The fixture below mirrors the live fetchSettings body.
import 'package:flutter_test/flutter_test.dart';
import 'package:shortzz/model/general/settings_model.dart';

Map<String, dynamic> _stringifiedSettingsJson() => {
      'status': true,
      'message': 'Settings Fetched',
      'data': {
        'id': '1',
        'app_name': 'Silitibum',
        'currency': 'K',
        'coin_value': '0.05',
        'min_redeem_coins': '300',
        'min_followers_for_live': '0',
        'admob_banner': null,
        'admob_android_status': '0',
        'admob_ios_status': '0',
        'max_upload_daily': '99',
        'max_story_daily': '10',
        'max_comment_daily': '10000',
        'max_comment_reply_daily': '10000',
        'max_post_pins': '3',
        'max_comment_pins': '2',
        'max_images_per_post': '5',
        'max_user_links': '1',
        'live_min_viewers': '0',
        'live_timeout': '0',
        'live_battle': '1',
        'zego_app_id': '1546043726',
        'zego_app_sign': '58022176cdc92b31a8ac7a4cc5d3f6a2',
        'is_compress': '0',
        'is_deepAR': '1',
        'deepar_android_key': 'abc123',
        'deepar_iOS_key': null,
        'is_withdrawal_on': '1',
        'help_mail': 'help@silitibum.com',
        'is_content_moderation': '1',
        'sight_engine_api_user': null,
        'sight_engine_api_secret': null,
        'sight_engine_image_workflow_id': null,
        'sight_engine_video_workflow_id': null,
        'gif_support': '1',
        'giphy_key': 'someGiphyKey',
        'watermark_status': '1',
        'watermark_image': null,
        'registration_bonus_status': '0',
        'registration_bonus_amount': '100',
        'privacy_policy': '<h1>Privacy Policy</h1>',
        'terms_of_uses': '<h1>Terms</h1>',
        'place_api_access_token': null,
        'created_at': '2026-07-01T00:00:00.000000Z',
        'updated_at': '2026-07-01T00:00:00.000000Z',
        'coinPackages': [
          {
            'id': '1',
            'image': null,
            'status': '1',
            'coin_amount': '100',
            'coin_plan_price': '5',
            'playstore_product_id': 'coins_100',
            'appstore_product_id': 'coins_100',
            'created_at': null,
            'updated_at': null,
          }
        ],
        'gifts': [
          {'id': '1', 'coin_price': '10', 'image': 'gift.png'}
        ],
        'languages': [
          {
            'id': '1',
            'code': 'en',
            'title': 'English',
            'status': '1',
            'is_default': '1',
          }
        ],
        'userLevels': [
          {'id': '1', 'level': '1', 'coins_collection': '0'}
        ],
        'onBoarding': [],
        'redeemGateways': [
          {'id': '1', 'title': 'Mobile Money'}
        ],
        'musicCategories': [],
        'reportReasons': [
          {'id': '1', 'title': 'Spam'}
        ],
        'deepARFilters': [],
      },
    };

void main() {
  test('Setting.fromJson tolerates all-string numerics (production shape)',
      () {
    final model = SettingModel.fromJson(_stringifiedSettingsJson());

    expect(model.status, true);
    final data = model.data;
    expect(data, isNotNull);
    expect(data!.coinValue, 0.05);
    expect(data.isContentModeration, 1);
    expect(data.isDeepAr, 1);
    expect(data.isWithdrawalOn, 1);
    expect(data.minRedeemCoins, 300);
    expect(data.zegoAppId, '1546043726');
    expect(data.zegoAppSign, isNotEmpty);
    expect(data.privacyPolicy, isNotEmpty);
    expect(data.termsOfUses, isNotEmpty);

    expect(data.coinPackages, hasLength(1));
    expect(data.coinPackages!.first.status, 1);
    expect(data.coinPackages!.first.coinAmount, 100);
    expect(data.coinPackages!.first.playStoreProductId, 'coins_100');

    expect(data.gifts!.first.coinPrice, 10);
    expect(data.languages!.first.isDefault, 1);
    expect(data.userLevels!.first.coinsCollection, 0);
    expect(data.redeemGateways!.first.id, 1);
    expect(data.reportReason!.first.id, 1);
  });

  test('Setting.fromJson also accepts properly typed numerics', () {
    final json = _stringifiedSettingsJson();
    final data = json['data'] as Map<String, dynamic>;
    data['coin_value'] = 0.05;
    data['is_content_moderation'] = 1;
    data['is_deepAR'] = 1;
    data['id'] = 1;

    final model = SettingModel.fromJson(json);
    expect(model.data?.coinValue, 0.05);
    expect(model.data?.isContentModeration, 1);
    expect(model.data?.isDeepAr, 1);
  });

  test('Setting.fromJson tolerates nulls everywhere', () {
    final model = SettingModel.fromJson({
      'status': true,
      'message': 'ok',
      'data': <String, dynamic>{},
    });
    expect(model.data, isNotNull);
    expect(model.data?.coinValue, isNull);
    expect(model.data?.zegoAppId, isNull);
    expect(model.data?.coinPackages, isEmpty);
  });
}

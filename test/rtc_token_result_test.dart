import 'package:flutter_test/flutter_test.dart';
import 'package:shortzz/common/service/api/livestream_service.dart';

/// livestream/fetchRtcToken, as production sends it (scalars may arrive as
/// strings).
void main() {
  test('reads the token, url and publish right', () {
    final res = RtcTokenResult.fromJson({
      'status': true,
      'message': 'rtc token issued',
      'data': {
        'url': 'wss://silitibum.livekit.cloud',
        'token': 'eyJhbGciOiJIUzI1NiJ9.e30.x',
        'identity': '175',
        'can_publish': 'true',
      },
    });

    expect(res.status, isTrue);
    expect(res.url, 'wss://silitibum.livekit.cloud');
    expect(res.token, isNotEmpty);
    expect(res.identity, '175');
    expect(res.canPublish, isTrue);
  });

  test('a refused token carries the reason and no credentials', () {
    final res = RtcTokenResult.fromJson(
        {'status': false, 'message': 'livestream_ended'});

    expect(res.status, isFalse);
    expect(res.message, 'livestream_ended');
    expect(res.token, isNull);
    expect(res.canPublish, isFalse);
  });
}

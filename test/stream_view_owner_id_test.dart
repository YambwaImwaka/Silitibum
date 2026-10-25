import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shortzz/screen/live_stream/livestream_screen/livestream_screen_controller.dart';

/// A view's id is the LiveKit identity of whoever's camera it shows: their
/// user id. Under Zego the host published under the room id the backend
/// builds as '<hostId>_<millis>', and `int.parse` on it crashed Go Live:
///
///   FormatException: Invalid radix-10 number (at character 1)
///   40_1785236249170
///
/// ownerUserId reads both forms and never throws.
void main() {
  StreamView viewWithId(String id) => StreamView(id, const SizedBox(), false);

  group('StreamView.ownerUserId', () {
    test('resolves a LiveKit identity, which is the user id', () {
      expect(viewWithId('40').ownerUserId, 40);
      expect(viewWithId('41').ownerUserId, 41);
    });

    test('still resolves a Zego-era room id to its host', () {
      // The exact id from the crash report.
      expect(viewWithId('40_1785236249170').ownerUserId, 40);
    });

    test('does not throw on ids it cannot resolve', () {
      expect(viewWithId('').ownerUserId, isNull);
      expect(viewWithId('not-a-number').ownerUserId, isNull);
      expect(viewWithId('_123').ownerUserId, isNull);
      expect(viewWithId('guest_g17298').ownerUserId, isNull);
    });

    test('finds the host view by host id', () {
      const int hostId = 40;
      final views = [viewWithId('40'), viewWithId('41')];

      expect(views.indexWhere((v) => v.ownerUserId == hostId), 0);
    });
  });
}

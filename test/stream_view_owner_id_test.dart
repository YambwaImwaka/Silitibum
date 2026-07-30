import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shortzz/screen/live_stream/livestream_screen/livestream_screen_controller.dart';

/// Regression cover for the Go Live crash:
///
///   FormatException: Invalid radix-10 number (at character 1)
///   40_1785236249170
///
/// The host publishes under the room id the backend builds as
/// '<hostId>_<millis>' (LivestreamApiController), co-hosts publish under a
/// bare numeric user id. Call sites used to `int.parse(streamId)` — fine for a
/// co-host, fatal for the host — or compare `streamId == '$hostId'`, which
/// never matched the host at all, so their name and avatar silently vanished.
void main() {
  StreamView viewWithId(String id) =>
      StreamView(id, 1, const SizedBox(), false);

  group('StreamView.ownerUserId', () {
    test('resolves the host, whose stream id is the room id', () {
      // The exact id from the crash report.
      expect(viewWithId('40_1785236249170').ownerUserId, 40);
    });

    test('resolves a co-host, whose stream id is a bare user id', () {
      expect(viewWithId('41').ownerUserId, 41);
    });

    test('does not throw on ids it cannot resolve', () {
      expect(viewWithId('').ownerUserId, isNull);
      expect(viewWithId('not-a-number').ownerUserId, isNull);
      expect(viewWithId('_123').ownerUserId, isNull);
    });

    test('host view matches its host id, which the old comparison missed', () {
      const int hostId = 40;
      final views = [viewWithId('40_1785236249170'), viewWithId('41')];

      // What the code does now.
      expect(views.indexWhere((v) => v.ownerUserId == hostId), 0);

      // What it used to do — the host was never found, so the sort that puts
      // the host first, the mute button and the battle score all no-op'd.
      expect(views.indexWhere((v) => v.streamId == '$hostId'), -1);
    });
  });
}

// The Live tab refreshes its room list every 15 s while someone is watching
// one of the rooms. A refresh must never move the room on screen, must bring
// in new rooms, and must drop ended ones — except the one being watched,
// which has to stay long enough to show that it ended.
import 'package:flutter_test/flutter_test.dart';
import 'package:shortzz/model/livestream/livestream.dart';
import 'package:shortzz/screen/live_stream/live_feed_screen/live_feed_screen_controller.dart';

Livestream room(String id, {int viewers = 0}) =>
    Livestream(roomID: id, watchingCount: viewers);

List<String?> ids(List<Livestream> rooms) => rooms.map((r) => r.roomID).toList();

void main() {
  test('first load takes the server order', () {
    final merged = mergeLiveRooms([], [room('a'), room('b')], null);
    expect(ids(merged.rooms), ['a', 'b']);
    expect(merged.currentEnded, isFalse);
  });

  test('rooms already shown keep their place; new rooms join the end', () {
    // The server orders by viewers, so a busy new room would come first.
    final merged = mergeLiveRooms(
        [room('a'), room('b')], [room('c', viewers: 99), room('b'), room('a')], 'b');
    expect(ids(merged.rooms), ['a', 'b', 'c']);
    expect(merged.currentEnded, isFalse);
  });

  test('fresh data replaces the shown copy', () {
    final merged = mergeLiveRooms([room('a', viewers: 1)], [room('a', viewers: 7)], 'a');
    expect(merged.rooms.single.watchingCount, 7);
  });

  test('ended rooms disappear, but the one being watched stays, marked ended', () {
    final merged = mergeLiveRooms([room('a'), room('b'), room('c')], [room('c')], 'b');
    expect(ids(merged.rooms), ['b', 'c']);
    expect(merged.currentEnded, isTrue);
  });

  test('everything ended while nothing was being watched leaves an empty tab', () {
    final merged = mergeLiveRooms([room('a')], [], null);
    expect(merged.rooms, isEmpty);
    expect(merged.currentEnded, isFalse);
  });
}

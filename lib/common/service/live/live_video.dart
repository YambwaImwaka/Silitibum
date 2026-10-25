import 'package:livekit_client/livekit_client.dart';
import 'package:shortzz/common/service/api/livestream_service.dart';

/// The server gave no video token: `livestream_ended`, or `live_unavailable`
/// when LiveKit is not set up on the backend.
class LiveVideoUnavailable implements Exception {
  final String reason;

  const LiveVideoUnavailable(this.reason);

  bool get roomEnded => reason == 'livestream_ended';

  @override
  String toString() => 'LiveVideoUnavailable($reason)';
}

/// Live video runs on LiveKit (Zego until 1.2.3, when its free trial lapsed
/// and every room login failed). Each live screen, and the Live tab's
/// preview, holds its own [Room]. The token comes from our server, which
/// decides who may publish: the host and the co-hosts they approve.
///
/// Participant identities are user ids, so `int.tryParse(identity)` is the
/// user; signed-out viewers are `guest_…` and never publish.
class LiveVideo {
  LiveVideo._();

  /// Adaptive stream sends each viewer only the resolution their screen
  /// needs and pauses video nobody is looking at; dynacast stops encoding
  /// layers no one watches. Both save mobile data, and keep the app inside
  /// LiveKit's free monthly allowance for longer.
  ///
  /// Leaving a room stops nothing by itself: a camera is stopped by whoever
  /// opened it. The go-live screen keeps its preview running under the host
  /// screen, so a live that fails to start can be retried straight away.
  static const RoomOptions roomOptions = RoomOptions(
    adaptiveStream: true,
    dynacast: true,
    stopLocalTrackOnUnpublish: false,
    defaultCameraCaptureOptions:
        CameraCaptureOptions(params: VideoParametersPresets.h720_169),
  );

  static Future<LocalVideoTrack> createCamera({bool front = true}) {
    return LocalVideoTrack.createCameraTrack(CameraCaptureOptions(
        cameraPosition: front ? CameraPosition.front : CameraPosition.back,
        params: VideoParametersPresets.h720_169));
  }

  /// Joins [roomId]'s video. Throws [LiveVideoUnavailable] when the server
  /// has no token for it.
  static Future<Room> connect(String roomId, {bool preview = false}) async {
    final RtcTokenResult res = await LivestreamService.instance
        .fetchRtcToken(roomId: roomId, preview: preview);
    final String? url = res.url;
    final String? token = res.token;
    if (res.status != true || url == null || token == null) {
      throw LiveVideoUnavailable(res.message ?? 'live_unavailable');
    }
    final Room room = Room(roomOptions: roomOptions);
    try {
      await room.connect(url, token);
    } catch (_) {
      await room.dispose();
      rethrow;
    }
    // Phones default WebRTC audio to the earpiece; a live is watched with
    // the phone held out.
    if (AudioManager.instance.canSwitchSpeakerphone) {
      AudioManager.instance
          .setSpeakerOutputPreferred(true)
          .catchError((_) {});
    }
    return room;
  }

  /// The user a participant is, or null for a guest.
  static int? userIdOf(Participant participant) =>
      int.tryParse(participant.identity);

  /// [participant]'s camera, once it is subscribed.
  static VideoTrack? cameraOf(Participant participant) {
    for (final publication in participant.videoTrackPublications) {
      final track = publication.track;
      if (track is VideoTrack && publication.source != TrackSource.screenShareVideo) {
        return track;
      }
    }
    return null;
  }

  /// Leaves and frees a room; safe on one that never finished connecting.
  /// The microphone is LiveKit's own track and goes with the room.
  static Future<void> close(Room? room) async {
    if (room == null) return;
    await stopMicrophone(room);
    try {
      await room.disconnect();
    } catch (_) {}
    try {
      await room.dispose();
    } catch (_) {}
  }

  /// Unpublishes and stops the microphone (setMicrophoneEnabled(true) made
  /// it), so the phone stops capturing audio.
  static Future<void> stopMicrophone(Room room) async {
    final LocalParticipant? me = room.localParticipant;
    if (me == null) return;
    for (final publication in me.audioTrackPublications.toList()) {
      final LocalAudioTrack? mic = publication.track;
      try {
        await me.removePublishedTrack(publication.sid);
      } catch (_) {}
      try {
        await mic?.stop();
      } catch (_) {}
    }
  }
}

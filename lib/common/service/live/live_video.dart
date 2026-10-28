import 'package:flutter/widgets.dart';
import 'package:flutter_webrtc/flutter_webrtc.dart' show Helper;
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
  ///
  /// A host sends 720p (up to 1.7 Mbps) with 360p and 180p copies for
  /// viewers on weak connections. When the host's phone or upload cannot
  /// keep up, WebRTC has to give something up: LiveKit's default for a
  /// camera keeps the frame rate and lowers the resolution, so a struggling
  /// host's live went soft for everyone. Balanced gives up some of each.
  static const RoomOptions roomOptions = RoomOptions(
    adaptiveStream: true,
    dynacast: true,
    stopLocalTrackOnUnpublish: false,
    defaultCameraCaptureOptions:
        CameraCaptureOptions(params: VideoParametersPresets.h720_169),
    defaultVideoPublishOptions: VideoPublishOptions(
        degradationPreference: DegradationPreference.balanced),
  );

  static Future<LocalVideoTrack> createCamera({bool front = true}) {
    return LocalVideoTrack.createCameraTrack(CameraCaptureOptions(
        cameraPosition: front ? CameraPosition.front : CameraPosition.back,
        params: VideoParametersPresets.h720_169));
  }

  /// Whether [camera] is the front one: what it was opened as, or last
  /// flipped to with [flipCamera].
  static bool isFront(LocalVideoTrack camera) {
    final options = camera.currentOptions;
    return options is! CameraCaptureOptions ||
        options.cameraPosition != CameraPosition.back;
  }

  /// Turns [camera] round, front to back or back to front, on the same
  /// track: nothing restarts, viewers keep the stream, and it works before
  /// the camera is published. LiveKit's setCameraPosition restarts the track
  /// instead, which throws on one that is not published yet (the go-live
  /// preview never flipped) and stops the picture for a moment in a live.
  /// Returns whether the front camera is on now.
  static Future<bool> flipCamera(LocalVideoTrack camera) async {
    final bool front = await Helper.switchCamera(camera.mediaStreamTrack);
    final options = camera.currentOptions;
    if (options is CameraCaptureOptions) {
      // Kept in step, so a track LiveKit restarts (a reconnect) reopens the
      // camera that is on now.
      camera.currentOptions = options.copyWith(
          cameraPosition: front ? CameraPosition.front : CameraPosition.back);
    }
    return front;
  }

  /// The phone's own camera, as its user sees it: mirrored for the front
  /// camera, like a selfie. Set here because LiveKit works its automatic
  /// mirroring out again only when it restarts the track itself, which
  /// [flipCamera] avoids.
  static VideoTrackRenderer selfView(LocalVideoTrack camera, {Key? key}) {
    return VideoTrackRenderer(camera,
        key: key,
        fit: VideoViewFit.cover,
        mirrorMode: isFront(camera)
            ? VideoViewMirrorMode.mirror
            : VideoViewMirrorMode.off);
  }

  /// Tokens fetched ahead for the live the viewer is likely to open next
  /// (the next one in the Live tab), so that swipe skips a round trip to our
  /// server. Kept briefly: a live can end, and the server is what says so.
  static final Map<String, ({RtcTokenResult token, DateTime at})> _prefetched =
      {};
  static const Duration _prefetchFor = Duration(seconds: 60);

  static String _key(String roomId, bool preview) => '$roomId|$preview';

  static Future<void> prefetchToken(String roomId,
      {bool preview = false}) async {
    final String key = _key(roomId, preview);
    final cached = _prefetched[key];
    if (cached != null && DateTime.now().difference(cached.at) < _prefetchFor) {
      return;
    }
    try {
      final RtcTokenResult res = await LivestreamService.instance
          .fetchRtcToken(roomId: roomId, preview: preview);
      if (res.status == true && res.url != null && res.token != null) {
        _prefetched[key] = (token: res, at: DateTime.now());
      }
    } catch (_) {}
  }

  /// Joins [roomId]'s video. Throws [LiveVideoUnavailable] when the server
  /// has no token for it.
  static Future<Room> connect(String roomId, {bool preview = false}) async {
    final cached = _prefetched.remove(_key(roomId, preview));
    final RtcTokenResult res =
        cached != null && DateTime.now().difference(cached.at) < _prefetchFor
            ? cached.token
            : await LivestreamService.instance
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

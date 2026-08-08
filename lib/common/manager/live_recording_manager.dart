import 'dart:async';
import 'dart:io';

import 'package:get/get.dart';
import 'package:path_provider/path_provider.dart';
import 'package:shortzz/common/manager/logger.dart';
import 'package:shortzz/common/manager/session_manager.dart';
import 'package:shortzz/common/service/api/livestream_recording_service.dart';
import 'package:shortzz/model/general/settings_model.dart';
import 'package:shortzz/model/post_story/post_model.dart';
import 'package:zego_express_engine/zego_express_engine.dart';

/// How far along storing an ended live is, for the end screen to report.
enum LiveRecordingState { idle, recording, uploading, saved, failed }

/// Owns recording the host's broadcast and getting it onto their profile.
///
/// Deliberately a process-wide singleton rather than part of
/// LivestreamScreenController: the upload outlives the live room by minutes,
/// and the controller is disposed the moment the host is moved to the end
/// screen. Anything holding the recording had to survive that disposal.
///
/// It also owns Zego's `onCapturedDataRecordStateUpdate` handler for the same
/// reason — LivestreamScreenController.stopListenEvent() nulls the room
/// handlers on dispose, which would drop the very callback that says the MP4
/// has finished being written.
class LiveRecordingManager {
  LiveRecordingManager._();

  static final LiveRecordingManager instance = LiveRecordingManager._();

  /// Below this, an ended live is treated as one that never really happened.
  static const int _minPublishableMs = 15 * 1000;

  final Rx<LiveRecordingState> state = LiveRecordingState.idle.obs;
  final RxDouble progress = 0.0.obs;

  /// The post the recording was published as, once it exists — the end screen
  /// uses it to offer "view your live".
  final Rxn<int> publishedPostId = Rxn<int>();

  String? _roomId;
  String? _filePath;
  String? _description;
  int? _startedAtMs;
  Timer? _capTimer;
  Timer? _finalizeFallback;
  bool _handedOff = false;

  Setting? get _settings => SessionManager.instance.getSettings();

  bool get isEnabled => (_settings?.livestreamRecordingEnabled ?? 1) == 1;

  int get _maxMinutes {
    final configured = _settings?.livestreamRecordingMaxMinutes ?? 60;
    return configured > 0 ? configured : 60;
  }

  /// Begins recording the host's captured audio and video to local storage.
  ///
  /// Best-effort throughout: a broadcast must never fail because it could not
  /// be recorded, so every path here swallows its error and leaves the stream
  /// running.
  Future<void> start({required String roomId}) async {
    if (!isEnabled || roomId.isEmpty) return;
    if (state.value == LiveRecordingState.recording) return;

    try {
      final dir = await getTemporaryDirectory();
      final safeRoom = roomId.replaceAll(RegExp(r'[^A-Za-z0-9_]'), '_');
      final path = '${dir.path}/live_recording_$safeRoom.mp4';

      // A file left behind by a previous crashed run would otherwise be
      // appended to or confused for this stream's recording.
      final stale = File(path);
      if (await stale.exists()) {
        await stale.delete();
      }

      _roomId = roomId;
      _filePath = path;
      _startedAtMs = DateTime.now().millisecondsSinceEpoch;
      _handedOff = false;
      publishedPostId.value = null;
      progress.value = 0;

      ZegoExpressEngine.onCapturedDataRecordStateUpdate =
          _onRecordStateUpdate;

      await ZegoExpressEngine.instance.startRecordingCapturedData(
          ZegoDataRecordConfig(path, ZegoDataRecordType.Default));

      state.value = LiveRecordingState.recording;
      Loggers.info('Live recording started: $path');

      // Stops recording — not the broadcast — once the file has grown to
      // something a phone can still upload. The host keeps streaming; only the
      // saved copy is truncated.
      _capTimer?.cancel();
      _capTimer = Timer(Duration(minutes: _maxMinutes), () {
        Loggers.info('Live recording hit the ${_maxMinutes}m cap');
        stopAndSave();
      });
    } catch (e) {
      Loggers.error('Could not start live recording: $e');
      state.value = LiveRecordingState.idle;
      _reset();
    }
  }

  /// Stops recording and starts the upload once the file is finalized.
  ///
  /// Safe to call more than once — the host ending a stream and the duration
  /// cap firing can both land here.
  Future<void> stopAndSave({String? description}) async {
    if (state.value != LiveRecordingState.recording) return;
    _description ??= description;
    _capTimer?.cancel();

    try {
      await ZegoExpressEngine.instance.stopRecordingCapturedData();
    } catch (e) {
      Loggers.error('stopRecordingCapturedData failed: $e');
    }

    // The Success callback is the reliable signal that the MP4 header has been
    // written, but it is a native callback that has been seen not to arrive at
    // all when the engine is torn down immediately afterwards. Upload from
    // whatever is on disk if it goes quiet.
    _finalizeFallback?.cancel();
    _finalizeFallback = Timer(const Duration(seconds: 4), () {
      Loggers.info('Record-state callback did not arrive; uploading anyway');
      _handOff();
    });
  }

  /// Abandons the recording without uploading it — used when the host's live
  /// never really started, so there is nothing worth publishing.
  Future<void> cancel() async {
    _capTimer?.cancel();
    _finalizeFallback?.cancel();
    if (state.value == LiveRecordingState.recording) {
      try {
        await ZegoExpressEngine.instance.stopRecordingCapturedData();
      } catch (e) {
        Loggers.error('stopRecordingCapturedData failed on cancel: $e');
      }
    }
    await _deleteLocalFile();
    state.value = LiveRecordingState.idle;
    _reset();
  }

  void _onRecordStateUpdate(ZegoDataRecordState recordState, int errorCode,
      ZegoDataRecordConfig config, ZegoPublishChannel channel) {
    if (errorCode != 0) {
      Loggers.error('Live recording error: $errorCode');
    }
    if (recordState == ZegoDataRecordState.Success) {
      _finalizeFallback?.cancel();
      _handOff();
    }
  }

  /// Uploads the finished file. Guarded by [_handedOff] because the Success
  /// callback and the fallback timer race each other by design.
  Future<void> _handOff() async {
    if (_handedOff) return;
    _handedOff = true;
    _finalizeFallback?.cancel();
    ZegoExpressEngine.onCapturedDataRecordStateUpdate = null;

    final roomId = _roomId;
    final path = _filePath;
    if (roomId == null || path == null) {
      state.value = LiveRecordingState.idle;
      return;
    }

    final file = File(path);
    if (!await file.exists() || await file.length() == 0) {
      Loggers.error('Live recording file is missing or empty: $path');
      state.value = LiveRecordingState.failed;
      _reset();
      return;
    }

    final durationMs = _startedAtMs != null
        ? DateTime.now().millisecondsSinceEpoch - _startedAtMs!
        : 0;

    // A live that barely started is not worth a post, and the thumbnail it
    // would need does not exist yet either: the retained snapshot is captured
    // five seconds into the broadcast, so finalizing before then has nothing
    // to publish with.
    if (durationMs < _minPublishableMs) {
      Loggers.info('Live too short to save (${durationMs}ms), discarding');
      await _deleteLocalFile();
      state.value = LiveRecordingState.idle;
      _reset();
      return;
    }

    state.value = LiveRecordingState.uploading;
    progress.value = 0;

    final PostModel? post = await LivestreamRecordingService.instance
        .uploadRecording(
            roomId: roomId,
            file: file,
            durationMs: durationMs,
            description: _description,
            onProgress: (value) => progress.value = value);

    if (post?.status == true) {
      publishedPostId.value = post?.data?.id;
      state.value = LiveRecordingState.saved;
      Loggers.success('Live recording published as post ${post?.data?.id}');
    } else {
      state.value = LiveRecordingState.failed;
      // Leaves nothing half-uploaded occupying server disk; the local file is
      // still removed below either way, since a retry surface does not exist.
      await LivestreamRecordingService.instance.discard(roomId: roomId);
    }

    await _deleteLocalFile();
    _reset();
  }

  Future<void> _deleteLocalFile() async {
    final path = _filePath;
    if (path == null) return;
    try {
      final file = File(path);
      if (await file.exists()) {
        await file.delete();
      }
    } catch (e) {
      Loggers.error('Could not delete local live recording: $e');
    }
  }

  void _reset() {
    _capTimer?.cancel();
    _finalizeFallback?.cancel();
    _capTimer = null;
    _finalizeFallback = null;
    _roomId = null;
    _filePath = null;
    _description = null;
    _startedAtMs = null;
  }
}

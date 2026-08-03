import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:shortzz/common/manager/logger.dart';
import 'package:shortzz/common/service/media/video_cache_service.dart';
import 'package:video_player/video_player.dart';

/// Watches a [VideoPlayerController] for a stall — buffering that doesn't
/// clear, or a playing position that stops advancing — for longer than
/// [stallThreshold]. On a stall it rebuilds the controller from the same
/// [url] once (seeking back to where playback stopped); if that retry also
/// stalls it calls [onGiveUp] instead of retrying forever, so callers can
/// show a "tap to retry" state rather than leaving the user staring at a
/// spinner indefinitely — no video surface in the app had this before.
class VideoStallWatcher {
  VideoStallWatcher({
    required this.url,
    required this.onControllerReplaced,
    required this.onGiveUp,
    this.stallThreshold = const Duration(seconds: 8),
    this.checkInterval = const Duration(seconds: 2),
  });

  final String url;
  final ValueChanged<VideoPlayerController> onControllerReplaced;
  final VoidCallback onGiveUp;
  final Duration stallThreshold;
  final Duration checkInterval;

  VideoPlayerController? _controller;
  Timer? _timer;
  Duration? _lastPosition;
  DateTime? _stalledSince;
  bool _hasRetried = false;
  bool _disposed = false;

  /// Start (or restart) watching [controller]. Call again after replacing
  /// the controller so the watcher tracks the new instance.
  void watch(VideoPlayerController controller) {
    _controller = controller;
    _lastPosition = null;
    _stalledSince = null;
    _timer?.cancel();
    _timer = Timer.periodic(checkInterval, (_) => _check());
  }

  Future<void> _check() async {
    if (_disposed) return;
    final controller = _controller;
    if (controller == null || !controller.value.isInitialized) return;

    final value = controller.value;

    if (!value.isPlaying && !value.isBuffering) {
      // Paused deliberately (by the user or visibility change) — not a stall.
      _stalledSince = null;
      _lastPosition = value.position;
      return;
    }

    final isStuck = value.isBuffering ||
        (value.isPlaying && value.position == _lastPosition);

    if (isStuck) {
      _stalledSince ??= DateTime.now();
      if (DateTime.now().difference(_stalledSince!) >= stallThreshold) {
        _stalledSince = null;
        await _recover();
      }
    } else {
      _stalledSince = null;
    }
    _lastPosition = value.position;
  }

  Future<void> _recover() async {
    if (_hasRetried) {
      Loggers.error('video stall retry also stalled for $url — giving up');
      onGiveUp();
      return;
    }
    _hasRetried = true;
    Loggers.error('video stall detected for $url — retrying once');

    final old = _controller;
    final wasPlaying = old?.value.isPlaying ?? true;
    final position = old?.value.position ?? Duration.zero;

    try {
      final fresh = await VideoCacheService.createController(url);
      await fresh.initialize();
      if (_disposed) {
        fresh.dispose();
        return;
      }
      if (position > Duration.zero) await fresh.seekTo(position);
      if (wasPlaying) fresh.play();
      await old?.dispose();
      watch(fresh);
      onControllerReplaced(fresh);
    } catch (e) {
      Loggers.error('video stall retry failed for $url: $e');
      onGiveUp();
    }
  }

  void dispose() {
    _disposed = true;
    _timer?.cancel();
  }
}

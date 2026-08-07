import 'dart:io';

import 'package:flutter_cache_manager/flutter_cache_manager.dart';
import 'package:shortzz/common/manager/logger.dart';
import 'package:video_player/video_player.dart';

/// Disk cache for feed/reel video files, separate from the image cache
/// cached_network_image manages. Reels get played from this cache when a
/// prefetch has already landed the file on disk, avoiding a second
/// network fetch for a video the app already downloaded.
class VideoCacheService {
  VideoCacheService._();

  static final CacheManager instance = CacheManager(
    Config(
      'shortzzVideoCache',
      stalePeriod: const Duration(days: 3),
      maxNrOfCacheObjects: 40,
    ),
  );

  /// Fire-and-forget background download. Safe to call repeatedly for the
  /// same url — the cache manager de-dupes an already in-flight download
  /// for that key instead of starting a second one.
  static void prefetch(String url) {
    if (url.isEmpty) return;
    instance.downloadFile(url).then((_) {}, onError: (e) {
      Loggers.error('video prefetch failed for $url: $e');
    });
  }

  /// Null when the video hasn't finished downloading yet — caller should
  /// fall back to network streaming in that case.
  static Future<File?> getCachedFile(String url) async {
    if (url.isEmpty) return null;
    final info = await instance.getFileFromCache(url);
    return info?.file;
  }

  /// Shared "play from disk if we already have it, otherwise stream" logic —
  /// previously duplicated across reels_screen_controller.dart, reel_seek_bar.dart
  /// and video_player_screen.dart. Callers must still call `.initialize()` on
  /// the returned controller.
  ///
  /// [cacheUrl] exists because for a transcoded post the url we *stream* and the
  /// url we can *cache* are not the same file. Once ffmpeg finishes,
  /// `Post.playbackVideoPath` points at an HLS master playlist — a manifest
  /// referencing dozens of `.ts` segments that ExoPlayer/AVPlayer fetch
  /// themselves, over which this Dart-side cache has no reach. Only the original
  /// MP4 (`Post.video`, which the backend keeps: PollTranscodeStatus adds
  /// `hls_path` but never deletes `video`) is a single file we can store and
  /// hand to `VideoPlayerController.file()`.
  ///
  /// So: prefetch the MP4, and if it landed, play *that* rather than streaming
  /// the HLS. A fully-downloaded fixed-bitrate file beats adaptive bitrate for a
  /// clip that is already on disk — ABR exists to survive a varying network, and
  /// there is no network left in the path. Without this the prefetcher spent
  /// bandwidth on MP4s that the player then ignored in favour of the HLS url.
  static Future<VideoPlayerController> createController(String url, {String? cacheUrl}) async {
    final cachedFile = await getCachedFile(cacheUrl ?? url);
    return cachedFile != null
        ? VideoPlayerController.file(cachedFile)
        : VideoPlayerController.networkUrl(Uri.parse(url));
  }
}

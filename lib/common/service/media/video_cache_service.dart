import 'dart:io';

import 'package:flutter_cache_manager/flutter_cache_manager.dart';
import 'package:shortzz/common/manager/logger.dart';

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
}

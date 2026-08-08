import 'dart:io';

import 'package:shortzz/common/manager/logger.dart';
import 'package:shortzz/common/service/api/api_service.dart';
import 'package:shortzz/common/service/utils/params.dart';
import 'package:shortzz/common/service/utils/web_service.dart';
import 'package:shortzz/model/general/status_model.dart';
import 'package:shortzz/model/post_story/post_model.dart';

/// Uploads the host's local recording of an ended livestream.
///
/// The file is sent in slices rather than as one request for two reasons that
/// both bite in the real deployment: the backend's post_max_size is 128M, which
/// a long live exceeds outright, and the upload runs from a phone that is very
/// often on mobile data — where one long-lived request is exactly the thing
/// that gets dropped. A slice that fails is retried on its own instead of
/// restarting the whole transfer.
class LivestreamRecordingService {
  LivestreamRecordingService._();

  static final LivestreamRecordingService instance =
      LivestreamRecordingService._();

  /// Comfortably under the server's per-request ceiling while still large
  /// enough that a long recording is not thousands of round trips.
  static const int chunkBytes = 6 * 1024 * 1024;

  /// Per-chunk retries. Enough to ride out a tunnel or a cell handover,
  /// bounded so a genuinely dead connection gives up rather than looping.
  static const int _maxChunkAttempts = 3;

  /// Sends [file] to the server for [roomId] and publishes it as a post.
  ///
  /// Returns the created post, or null if the upload could not be completed —
  /// callers treat a failure as "the live simply was not saved" and must never
  /// surface it as a broadcast error, because by this point the broadcast has
  /// already finished successfully.
  Future<PostModel?> uploadRecording({
    required String roomId,
    required File file,
    required int durationMs,
    String? description,
    void Function(double progress)? onProgress,
  }) async {
    final int totalBytes = await file.length();
    if (totalBytes <= 0) {
      Loggers.error('Livestream recording is empty, nothing to upload');
      return null;
    }

    final int totalChunks = (totalBytes / chunkBytes).ceil();
    final handle = await file.open();

    try {
      for (int index = 0; index < totalChunks; index++) {
        final int offset = index * chunkBytes;
        final int size =
            offset + chunkBytes > totalBytes ? totalBytes - offset : chunkBytes;

        await handle.setPosition(offset);
        final bytes = await handle.read(size);

        final sent = await _sendChunkWithRetry(
          roomId: roomId,
          index: index,
          totalChunks: totalChunks,
          bytes: bytes,
        );
        if (!sent) {
          Loggers.error('Livestream recording upload gave up at chunk $index');
          return null;
        }

        // Finalizing is the last few percent: transcoding continues on the
        // server afterwards, so reporting 100% at the last chunk would be a
        // lie the end screen then has to walk back.
        onProgress?.call((index + 1) / totalChunks * 0.95);
      }
    } finally {
      await handle.close();
    }

    final post = await _finalize(
      roomId: roomId,
      totalChunks: totalChunks,
      durationMs: durationMs,
      description: description,
    );
    if (post != null) {
      onProgress?.call(1);
    }
    return post;
  }

  Future<bool> _sendChunkWithRetry({
    required String roomId,
    required int index,
    required int totalChunks,
    required List<int> bytes,
  }) async {
    for (int attempt = 1; attempt <= _maxChunkAttempts; attempt++) {
      try {
        final StatusModel result = await ApiService.instance.multiPartCallApi(
          url: WebService.livestream.uploadRecordingChunk,
          param: {
            Params.roomId: roomId,
            Params.chunkIndex: index,
            Params.totalChunks: totalChunks,
          },
          filesMap: const {},
          bytesFiles: {
            Params.chunk: (bytes: bytes, filename: 'chunk_$index.part'),
          },
          fromJson: StatusModel.fromJson,
        );
        if (result.status == true) {
          return true;
        }
        // A false status is the server rejecting this request on its merits
        // (recording disabled, not the host, already published). Retrying
        // sends the identical request to the identical answer.
        Loggers.error('Chunk $index rejected: ${result.message}');
        return false;
      } catch (e) {
        Loggers.error('Chunk $index attempt $attempt failed: $e');
        if (attempt == _maxChunkAttempts) return false;
        // The chunk is keyed by index server-side, so a retry overwrites its
        // own slot — resending after a partial write is safe.
        await Future.delayed(Duration(seconds: attempt * 2));
      }
    }
    return false;
  }

  Future<PostModel?> _finalize({
    required String roomId,
    required int totalChunks,
    required int durationMs,
    String? description,
  }) async {
    try {
      final PostModel post = await ApiService.instance.call(
        url: WebService.livestream.finalizeLivestreamRecording,
        param: {
          Params.roomId: roomId,
          Params.totalChunks: totalChunks,
          Params.durationMs: durationMs,
          Params.description: description,
        },
        fromJson: PostModel.fromJson,
      );
      if (post.status != true) {
        Loggers.error('Finalize recording failed: ${post.message}');
        return null;
      }
      return post;
    } catch (e) {
      Loggers.error('Finalize recording threw: $e');
      return null;
    }
  }

  /// Drops a partial upload server-side so the slices already sent are not left
  /// occupying disk until a sweep notices them.
  Future<void> discard({required String roomId}) async {
    try {
      await ApiService.instance.call(
        url: WebService.livestream.discardLivestreamRecording,
        param: {Params.roomId: roomId},
        fromJson: StatusModel.fromJson,
      );
    } catch (e) {
      Loggers.error('Discard recording failed: $e');
    }
  }
}

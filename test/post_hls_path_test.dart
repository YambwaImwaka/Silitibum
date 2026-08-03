// Guards the HLS playback-source selection against the same trap
// settings_model_parse_test.dart documents: production serves numeric columns
// as JSON *strings*, so `video_status` arrives as "2", not 2. A plain
// `json['video_status'] == 2` comparison would silently never match and every
// viewer would keep getting the single-bitrate MP4 while the transcoded
// adaptive renditions sat unused on disk. The fixtures below mirror real
// fetchPostById bodies from production.
import 'package:flutter_test/flutter_test.dart';
import 'package:shortzz/model/post_story/post_model.dart';

Map<String, dynamic> _postJson({
  dynamic videoStatus,
  String? hlsPath,
}) =>
    {
      'id': '242',
      'post_type': '1',
      'user_id': '42',
      'description': '#trending first snap',
      'video': 'uploads/1785661045_Silitibum_filter_video.mp4',
      'video_status': videoStatus,
      'hls_path': hlsPath,
      'thumbnail': 'uploads/thumb.jpg',
      'views': '3',
    };

void main() {
  group('Post.playbackVideoPath', () {
    test('prefers HLS when transcoding finished (status arrives as a string)', () {
      final post = Post.fromJson(
        _postJson(videoStatus: '2', hlsPath: 'hls/242/master.m3u8'),
      );

      expect(post.videoStatus, 2, reason: 'string "2" must coerce to int 2');
      expect(post.playbackVideoPath, 'hls/242/master.m3u8');
    });

    test('falls back to the MP4 while still transcoding', () {
      final post = Post.fromJson(_postJson(videoStatus: '1', hlsPath: null));

      expect(post.playbackVideoPath,
          'uploads/1785661045_Silitibum_filter_video.mp4');
    });

    test('falls back to the MP4 when transcoding failed', () {
      final post = Post.fromJson(_postJson(videoStatus: '3', hlsPath: null));

      expect(post.playbackVideoPath,
          'uploads/1785661045_Silitibum_filter_video.mp4');
    });

    test('falls back to the MP4 for posts predating the HLS columns', () {
      // Chat payloads and any cached/legacy post omit these fields entirely.
      final post = Post.fromJson(_postJson(videoStatus: null, hlsPath: null));

      expect(post.videoStatus, isNull);
      expect(post.playbackVideoPath,
          'uploads/1785661045_Silitibum_filter_video.mp4');
    });

    test('ignores a stale hls_path when status is not ready', () {
      // Defensive: a failed re-transcode could leave a path behind.
      final post = Post.fromJson(
        _postJson(videoStatus: '3', hlsPath: 'hls/242/master.m3u8'),
      );

      expect(post.playbackVideoPath,
          'uploads/1785661045_Silitibum_filter_video.mp4');
    });

    test('survives an int-typed status if the backend ever stops stringifying', () {
      final post = Post.fromJson(
        _postJson(videoStatus: 2, hlsPath: 'hls/242/master.m3u8'),
      );

      expect(post.playbackVideoPath, 'hls/242/master.m3u8');
    });
  });
}

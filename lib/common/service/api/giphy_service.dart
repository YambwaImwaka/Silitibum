import 'dart:convert';

import 'package:http/http.dart' as http;
import 'package:shortzz/common/manager/logger.dart';
import 'package:shortzz/model/giphy/giphy_model.dart';

// https://developers.giphy.com/docs/optional-settings/#rating
enum GiphyRating {
  g,
  pg,
  pg_13,
  r;

  String get title {
    switch (this) {
      case GiphyRating.g:
        return 'g';
      case GiphyRating.pg:
        return 'pg';
      case GiphyRating.pg_13:
        return 'pg-13';
      case GiphyRating.r:
        return 'r';
    }
  }
}

class GiphyService {
  GiphyService._();

  static final GiphyService instance = GiphyService._();

  int paginationLimit = 30;

  Future<List<GiphyData>> search({
    required String apiKey,
    required String keyWord,
    required int startCount,
    GiphyRating giphyRating = GiphyRating.g,
  }) async {
    if (apiKey.isEmpty) return [];
    String url =
        'https://api.giphy.com/v1/stickers/search?api_key=$apiKey&q=$keyWord&limit=$paginationLimit&offset=$startCount&rating=${giphyRating.title}';
    Loggers.info(url);
    return _fetch(url);
  }

  Future<List<GiphyData>> trending(
      {required String apiKey,
      GiphyRating giphyRating = GiphyRating.g,
      required int startCount}) async {
    // No key is set in the admin panel: Giphy can only refuse, and this ran
    // on every app start (the GIF sheet preloads).
    if (apiKey.isEmpty) return [];
    String url =
        'https://api.giphy.com/v1/stickers/trending?api_key=$apiKey&limit=$paginationLimit&offset=$startCount&rating=${giphyRating.title}';
    Loggers.info(url);
    return _fetch(url);
  }

  /// No GIFs, rather than an error, when Giphy is slow or unreachable: the
  /// timeouts were reported as app crashes.
  Future<List<GiphyData>> _fetch(String url) async {
    try {
      final http.Response response = await http
          .get(Uri.parse(url))
          .timeout(const Duration(seconds: 15));
      if (response.statusCode == 200) {
        return GiphyModel.fromJson(jsonDecode(response.body)).data ?? [];
      }
    } catch (e) {
      Loggers.error('Giphy request failed: $e');
    }
    return [];
  }
}

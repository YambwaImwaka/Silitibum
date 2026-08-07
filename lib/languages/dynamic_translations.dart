import 'dart:convert';

import 'package:get/get.dart';
import 'package:get_storage/get_storage.dart';
import 'package:shortzz/common/manager/logger.dart';

/// Runtime translation table, backed by a local cache.
///
/// Translations arrive as CSV files downloaded from the backend. Previously
/// every enabled language was fetched and parsed on the splash screen before
/// the app would navigate anywhere, and the result was then applied by
/// rebuilding the entire widget tree — so a cold start paid for a set of
/// network round trips plus a full restart before showing anything.
///
/// Now the last known table is persisted and restored synchronously at
/// startup, so the app can render translated text immediately and refresh the
/// files in the background.
class DynamicTranslations extends Translations {
  DynamicTranslations() {
    _restoreFromCache();
  }

  static const String _cacheKey = 'dynamic_translations';

  final Map<String, Map<String, String>> _keys = {};

  final GetStorage _storage = GetStorage('shortzz');

  @override
  Map<String, Map<String, String>> get keys => _keys;

  /// True when there is something to render with, i.e. the splash does not
  /// have to block on a download before navigating.
  bool get hasTranslations => _keys.isNotEmpty;

  bool hasLanguage(String code) => (_keys[code]?.isNotEmpty ?? false);

  void addTranslations(Map<String, Map<String, String>> map,
      {bool persist = true}) {
    map.forEach((lang, translations) {
      if (_keys.containsKey(lang)) {
        _keys[lang]?.addAll(translations); // Update existing translations
      } else {
        _keys[lang] = translations; // Add new language
      }
    });

    if (persist) _persist();
  }

  void _restoreFromCache() {
    try {
      final raw = _storage.read(_cacheKey);
      if (raw == null) return;

      final decoded = jsonDecode(raw is String ? raw : jsonEncode(raw));
      if (decoded is! Map) return;

      decoded.forEach((lang, translations) {
        if (translations is Map) {
          _keys['$lang'] = translations
              .map((key, value) => MapEntry('$key', '$value'));
        }
      });
    } catch (e) {
      // A corrupt cache must never stop the app from starting — it just
      // means this launch falls back to the literal English keys until the
      // background refresh lands.
      Loggers.error('Translation cache restore failed: $e');
    }
  }

  void _persist() {
    try {
      _storage.write(_cacheKey, jsonEncode(_keys));
    } catch (e) {
      Loggers.error('Translation cache write failed: $e');
    }
  }
}

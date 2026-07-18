import 'dart:developer' as developer;

import 'package:flutter/foundation.dart';

// All logging is debug-only. Note: arguments are still evaluated at call
// sites, so anything expensive to build (e.g. re-encoding a whole JSON
// response) must additionally be guarded with kDebugMode at the call site.
class Loggers {
  static void info(Object? msg) {
    if (kDebugMode) developer.log('$msg', name: 'INFO');
  }

  static void success(Object? msg) {
    if (kDebugMode) developer.log('✅✅✅: $msg', name: 'SUCCESS');
  }

  static void warning(Object? msg) {
    if (kDebugMode) developer.log('⚠️⚠️⚠️: $msg', name: 'WARNING');
  }

  static void error(Object? msg) {
    if (kDebugMode) developer.log('🔴🔴🔴: $msg', name: 'ERROR');
  }
}

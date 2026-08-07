import 'dart:async';

import 'package:flutter/material.dart';

/// Trailing-edge debouncer, keyed by tag.
///
/// This used to expose a single `shared` instance holding ONE [Timer] that
/// ~28 call sites all funnelled through, so unrelated actions cancelled each
/// other. Two user-visible bugs came from that: the pending 3s view-count
/// POST was killed by any like/save/search that happened in the same window,
/// and the music trim sheet's initial `playPause()` never fired — the
/// waveform rendered but no audio ever played.
///
/// Each tag now owns its own timer, so calls with different tags never
/// interfere. Use one tag per logical action; share a tag only when the calls
/// genuinely should cancel one another (e.g. collapsing concurrent 401s into
/// a single navigation).
class DebounceAction {
  Timer? _debounce;

  static final Map<String, DebounceAction> _instances = {};

  /// The debouncer for [tag], created on first use.
  static DebounceAction tagged(String tag) =>
      _instances.putIfAbsent(tag, DebounceAction.new);

  void call(VoidCallback action, {int? milliseconds}) {
    _debounce?.cancel();
    _debounce = Timer(Duration(milliseconds: milliseconds ?? 500), action);
  }

  /// Drops a pending action without running it.
  void cancel() {
    _debounce?.cancel();
    _debounce = null;
  }
}

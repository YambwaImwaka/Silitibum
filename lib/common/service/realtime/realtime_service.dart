import 'dart:async';

import 'package:dart_pusher_channels/dart_pusher_channels.dart';
import 'package:get/get.dart';
import 'package:shortzz/common/manager/logger.dart';
import 'package:shortzz/common/manager/session_manager.dart';
import 'package:shortzz/common/service/utils/params.dart';
import 'package:shortzz/utilities/const_res.dart';

/// Connection state of the realtime layer. Anything other than [connected]
/// means screens should run their REST polling fallback.
enum RealtimeState { disconnected, connecting, connected }

/// A realtime event forwarded from any subscribed channel.
class RealtimeEvent {
  final String channel; // full channel name, e.g. private-chat.thread.7
  final String name; // event name, e.g. message.sent
  final Map<String, dynamic> data;

  RealtimeEvent({required this.channel, required this.name, required this.data});
}

/// Pusher-protocol WebSocket layer (replaces Firestore snapshots). Speaks to
/// Pusher cloud, Soketi or Laravel Reverb — whichever the backend .env points
/// at — via the same `broadcasting/auth` endpoint secured with the app's
/// apikey + authtoken headers.
///
/// With [realtimeAppKey] empty the service never connects and every consumer
/// stays on polling, so the app works before any Pusher account exists.
class RealtimeService {
  RealtimeService._();

  static final RealtimeService instance = RealtimeService._();

  PusherChannelsClient? _client;
  final Rx<RealtimeState> state = RealtimeState.disconnected.obs;
  final StreamController<RealtimeEvent> _events = StreamController.broadcast();
  final Map<String, PrivateChannel> _privateChannels = {};
  final Map<String, PresenceChannel> _presenceChannels = {};
  final Map<String, List<StreamSubscription>> _channelSubs = {};
  final List<StreamSubscription> _clientSubs = [];

  Stream<RealtimeEvent> get events => _events.stream;

  bool get isEnabled => realtimeAppKey.isNotEmpty;

  bool get isConnected => state.value == RealtimeState.connected;

  static const List<String> chatEvents = [
    'message.sent',
    'message.unsent',
    'thread.updated',
    'thread.deleted',
  ];

  static const List<String> livestreamEvents = [
    'livestream.updated',
    'user_state.updated',
    'comment.sent',
    'gift.sent',
    'battle.updated',
    'livestream.ended',
  ];

  Uri get _authEndpoint => Uri.parse('${apiURL}broadcasting/auth');

  Map<String, String> get _authHeaders => {
        Params.apikey: apiKey,
        Params.authToken: SessionManager.instance.getAuthToken(),
      };

  /// Connect after login (dashboard startup). Safe to call repeatedly.
  Future<void> connect() async {
    if (!isEnabled || !SessionManager.instance.isLogin()) return;
    if (_client != null) return;

    state.value = RealtimeState.connecting;
    final options = realtimeHost.isNotEmpty
        ? const PusherChannelsOptions.fromHost(
            scheme: 'wss', host: realtimeHost, key: realtimeAppKey)
        : const PusherChannelsOptions.fromCluster(
            scheme: 'wss', cluster: realtimeCluster, key: realtimeAppKey);

    final client = PusherChannelsClient.websocket(
        options: options,
        connectionErrorHandler: (exception, trace, refresh) {
          Loggers.error('Realtime connection error: $exception');
          state.value = RealtimeState.disconnected;
          refresh(); // package-managed reconnect with backoff
        });
    _client = client;

    _clientSubs.add(client.onConnectionEstablished.listen((_) {
      state.value = RealtimeState.connected;
      for (final channel in _privateChannels.values) {
        channel.subscribeIfNotUnsubscribed();
      }
      for (final channel in _presenceChannels.values) {
        channel.subscribeIfNotUnsubscribed();
      }
    }));
    _clientSubs.add(client.lifecycleStream.listen((lifecycle) {
      if (lifecycle == PusherChannelsClientLifeCycleState.disconnected ||
          lifecycle == PusherChannelsClientLifeCycleState.connectionError) {
        state.value = RealtimeState.disconnected;
      }
    }));

    // Personal channel: thread-list updates, unread badge, new messages.
    subscribePrivate('user.${SessionManager.instance.getUserID()}', chatEvents);

    try {
      await client.connect();
    } catch (e) {
      Loggers.error('Realtime connect failed: $e');
      state.value = RealtimeState.disconnected;
    }
  }

  /// Subscribes to `private-<shortName>` and forwards [eventNames] into
  /// [events]. No-op when already subscribed or the service is disabled.
  void subscribePrivate(String shortName, List<String> eventNames) {
    final client = _client;
    if (client == null) return;
    final channelName = 'private-$shortName';
    if (_privateChannels.containsKey(channelName)) return;

    final channel = client.privateChannel(channelName,
        authorizationDelegate:
            EndpointAuthorizableChannelTokenAuthorizationDelegate
                .forPrivateChannel(
                    authorizationEndpoint: _authEndpoint,
                    headers: _authHeaders));
    _privateChannels[channelName] = channel;
    _channelSubs[channelName] = [
      for (final name in eventNames)
        channel.bind(name).listen((event) => _forward(channelName, name, event))
    ];
    if (isConnected) channel.subscribeIfNotUnsubscribed();
  }

  /// Subscribes to `presence-<shortName>`; besides [eventNames], member
  /// added/removed are forwarded as `member.added` / `member.removed`.
  /// Returns the channel so callers can read members / trigger client events.
  PresenceChannel? subscribePresence(String shortName, List<String> eventNames) {
    final client = _client;
    if (client == null) return null;
    final channelName = 'presence-$shortName';
    if (_presenceChannels.containsKey(channelName)) {
      return _presenceChannels[channelName];
    }

    final channel = client.presenceChannel(channelName,
        authorizationDelegate:
            EndpointAuthorizableChannelTokenAuthorizationDelegate
                .forPresenceChannel(
                    authorizationEndpoint: _authEndpoint,
                    headers: _authHeaders));
    _presenceChannels[channelName] = channel;
    _channelSubs[channelName] = [
      for (final name in eventNames)
        channel.bind(name).listen((event) => _forward(channelName, name, event)),
      channel
          .bind(Channel.memberAddedEventName)
          .listen((event) => _forward(channelName, 'member.added', event)),
      channel
          .bind(Channel.memberRemovedEventName)
          .listen((event) => _forward(channelName, 'member.removed', event)),
    ];
    if (isConnected) channel.subscribeIfNotUnsubscribed();
    return channel;
  }

  void unsubscribe(String fullChannelName) {
    for (final sub in _channelSubs.remove(fullChannelName) ?? []) {
      sub.cancel();
    }
    _privateChannels.remove(fullChannelName)?.unsubscribe();
    _presenceChannels.remove(fullChannelName)?.unsubscribe();
  }

  void _forward(String channelName, String name, ChannelReadEvent event) {
    _events.add(RealtimeEvent(
        channel: channelName,
        name: name,
        data: event.tryGetDataAsMap() ?? <String, dynamic>{}));
  }

  /// Tear down on logout / account deletion — a later login reconnects with
  /// the new user's channel.
  Future<void> disconnect() async {
    for (final subs in _channelSubs.values) {
      for (final sub in subs) {
        sub.cancel();
      }
    }
    _channelSubs.clear();
    _privateChannels.clear();
    _presenceChannels.clear();
    for (final sub in _clientSubs) {
      sub.cancel();
    }
    _clientSubs.clear();
    try {
      _client?.dispose();
    } catch (e) {
      Loggers.error('Realtime dispose failed: $e');
    }
    _client = null;
    state.value = RealtimeState.disconnected;
  }
}

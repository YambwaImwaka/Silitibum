const String baseURL = 'https://silitibum.com/';
const String apiURL = '${baseURL}api/';
const String apiKey = 'silitibum';

// If you change this topic you also change backend .env file
String notificationTopic = "silitibum";

// Realtime (Pusher-protocol) config — must match the backend .env PUSHER_*
// keys. Leave realtimeAppKey empty to disable WebSockets entirely; chat and
// livestreams then run on the REST polling fallback only.
// To self-host later (Soketi/Reverb), set realtimeHost to the server and the
// key/cluster to whatever the daemon is configured with.
// Pusher cloud app 2180887, cluster mt1. This is the PUBLIC app key — it is
// designed to ship in clients, and on its own it grants nothing: every channel
// this app uses is private-/presence-, so subscribing still requires an HMAC
// signature that only the backend can mint (BroadcastAuthController, gated on
// the authtoken header). The app SECRET must never appear here; it lives only
// in the server .env.
//
// Setting this non-empty is what switches the app off REST polling and onto
// WebSockets — see RealtimeService.isEnabled. It only pays off if the backend
// also has BROADCAST_DRIVER=pusher; with the backend still on `log` the socket
// connects and no event ever arrives, which is why the livestream controller
// keeps a 10s safety-net reconcile rather than trusting isConnected alone.
const String realtimeAppKey = '7d38be2f46227a898b0b';
const String realtimeCluster = 'mt1';
const String realtimeHost = ''; // empty = Pusher cloud cluster hosts

// RevenueCat Test Store key (test_...) — works for sandbox testing without
// Play/App Store credentials. Before a store release, replace with the real
// platform keys (goog_... for Android, appl_... for iOS) from the RevenueCat
// dashboard → Project settings → API keys.
String revenueCatAndroidApiKey = "test_bbuAgHoIIjxANexPgXtxYwapofF";
String revenueCatAppleApiKey = "________"; // placeholder — safely ignored

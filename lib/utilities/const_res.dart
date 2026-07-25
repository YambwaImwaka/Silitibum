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
const String realtimeAppKey = '';
const String realtimeCluster = 'mt1';
const String realtimeHost = ''; // empty = Pusher cloud cluster hosts

// RevenueCat Test Store key (test_...) — works for sandbox testing without
// Play/App Store credentials. Before a store release, replace with the real
// platform keys (goog_... for Android, appl_... for iOS) from the RevenueCat
// dashboard → Project settings → API keys.
String revenueCatAndroidApiKey = "test_bbuAgHoIIjxANexPgXtxYwapofF";
String revenueCatAppleApiKey = "________"; // placeholder — safely ignored

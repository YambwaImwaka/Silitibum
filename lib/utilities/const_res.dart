const String baseURL = 'https://silitibum.com/';
const String apiURL = '${baseURL}api/';
const String apiKey = 'silitibum';

// Internal-only domain for the email/password credential linked to phone
// accounts (phone + password login without per-login SMS). Never emailed,
// never shown to users. Must match the check in the backend's logInUser.
const String phoneAliasEmailDomain = 'phone.silitibum.com';

// If you change this topic you also change backend .env file
String notificationTopic = "silitibum";

String revenueCatAndroidApiKey = "______"; // revenueCat android api
String revenueCatAppleApiKey = "________"; // revenueCat apple api

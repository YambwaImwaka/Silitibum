# R8/ProGuard keep rules for release builds (minifyEnabled + shrinkResources).
#
# Deliberately conservative: every SDK below either resolves classes by name at
# runtime or is a native bridge whose Java side is only ever called from C++ or
# from Dart via a channel. R8 cannot see those call sites, so without an
# explicit keep it strips the class and the failure shows up ONLY in a release
# build, usually as a ClassNotFoundException at the moment a user opens the
# feature. Shrinking the remaining (much larger) surface still gets most of the
# size win, so keeping these whole is the right trade.

# ---- Flutter embedding -------------------------------------------------
-keep class io.flutter.app.** { *; }
-keep class io.flutter.plugin.** { *; }
-keep class io.flutter.util.** { *; }
-keep class io.flutter.view.** { *; }
-keep class io.flutter.** { *; }
-keep class io.flutter.plugins.** { *; }
-dontwarn io.flutter.embedding.**

# ---- LiveKit / WebRTC (livestream) -------------------------------------
# libjingle calls back into these Java classes over JNI by name; R8 must
# neither rename nor strip them.
-keep class org.webrtc.** { *; }
-dontwarn org.webrtc.**
-keep class com.cloudwebrtc.webrtc.** { *; }
-keep class io.livekit.** { *; }
-dontwarn io.livekit.**

# ---- DeepAR (camera filters) -------------------------------------------
-keep class ai.deepar.** { *; }
-dontwarn ai.deepar.**
# The plugin itself: MainActivity.guardDeepArDetach sets two of its fields
# by name, so they must not be renamed.
-keep class com.deepar.ai.** { *; }

# ---- RevenueCat (purchases) --------------------------------------------
# Billing/entitlement models are deserialized reflectively; a stripped field
# silently becomes null, which here would mean a paid user losing entitlements.
-keep class com.revenuecat.purchases.** { *; }
-dontwarn com.revenuecat.purchases.**
-keep class com.android.billingclient.** { *; }

# ---- flutter_local_notifications ---------------------------------------
# Serializes scheduled notifications with Gson; without this, rescheduling
# after a reboot fails in release only.
-keep class com.dexterous.** { *; }
-keep class com.google.gson.** { *; }
-keep class * implements com.google.gson.TypeAdapterFactory
-keep class * implements com.google.gson.JsonSerializer
-keep class * implements com.google.gson.JsonDeserializer
-keepattributes Signature
-keepattributes *Annotation*

# ---- Firebase / Crashlytics --------------------------------------------
# SourceFile/LineNumberTable keep release stack traces readable; without them
# Crashlytics reports are obfuscated frames with no line numbers.
-keepattributes SourceFile,LineNumberTable
-keep class com.google.firebase.** { *; }
-dontwarn com.google.firebase.**

# ---- Google Mobile Ads / Play Services ---------------------------------
-keep class com.google.android.gms.** { *; }
-dontwarn com.google.android.gms.**

# ---- WebView JS bridge --------------------------------------------------
-keepclassmembers class * {
    @android.webkit.JavascriptInterface <methods>;
}

# ---- General Android correctness ---------------------------------------
-keepclassmembers class * implements android.os.Parcelable {
    public static final android.os.Parcelable$Creator *;
}
-keepclasseswithmembernames class * {
    native <methods>;
}
-keepclassmembers enum * {
    public static **[] values();
    public static ** valueOf(java.lang.String);
}

# Desugaring support (coreLibraryDesugaring in build.gradle)
-dontwarn java.lang.invoke.**
-dontwarn **$$Lambda$*

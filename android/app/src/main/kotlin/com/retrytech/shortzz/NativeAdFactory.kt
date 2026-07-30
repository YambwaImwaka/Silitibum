package com.silitibum.app

import android.view.LayoutInflater
import android.view.View
import android.widget.Button
import android.widget.ImageView
import android.widget.TextView
import com.google.android.gms.ads.nativead.NativeAd
import com.google.android.gms.ads.nativead.NativeAdView
import io.flutter.plugins.googlemobileads.GoogleMobileAdsPlugin

/**
 * Builds the full-bleed [NativeAdView] shown inside the Snaps feed.
 *
 * Registered under the id "snapNativeAdFactory" in [MainActivity]; the Dart side
 * requests it by that same id (see NativeAdReelCard).
 *
 * Every asset an ad may or may not carry (icon, body, call-to-action,
 * advertiser) is optional in the Native Ads API — an unpopulated asset whose
 * view is left VISIBLE renders as a blank gap, and a stale value from a
 * recycled view renders as the *previous* ad's text. So each one is explicitly
 * set-and-shown or hidden, never left alone.
 */
class SnapNativeAdFactory(private val layoutInflater: LayoutInflater) :
    GoogleMobileAdsPlugin.NativeAdFactory {

    override fun createNativeAd(
        nativeAd: NativeAd,
        customOptions: MutableMap<String, Any>?
    ): NativeAdView {
        val adView =
            layoutInflater.inflate(R.layout.native_ad_layout, null) as NativeAdView

        val headlineView = adView.findViewById<TextView>(R.id.ad_headline)
        val bodyView = adView.findViewById<TextView>(R.id.ad_body)
        val iconView = adView.findViewById<ImageView>(R.id.ad_icon)
        val ctaView = adView.findViewById<Button>(R.id.ad_call_to_action)
        val advertiserView = adView.findViewById<TextView>(R.id.ad_advertiser)
        val mediaView = adView.mediaView

        // Headline is the one asset guaranteed to be present.
        headlineView.text = nativeAd.headline
        adView.headlineView = headlineView

        bodyView.applyOptionalText(nativeAd.body)
        adView.bodyView = bodyView

        ctaView.applyOptionalText(nativeAd.callToAction)
        adView.callToActionView = ctaView

        // Advertiser name, falling back to the store name — one or the other is
        // usually present, and the row looks broken with neither.
        advertiserView.applyOptionalText(nativeAd.advertiser ?: nativeAd.store)
        adView.advertiserView = advertiserView

        val icon = nativeAd.icon
        if (icon == null) {
            iconView.visibility = View.GONE
        } else {
            iconView.setImageDrawable(icon.drawable)
            iconView.visibility = View.VISIBLE
        }
        adView.iconView = iconView

        if (mediaView != null) {
            // Fill the slot like a Snap video instead of letterboxing inside it.
            mediaView.setImageScaleType(ImageView.ScaleType.CENTER_CROP)
            adView.mediaView = mediaView
        }

        // Must be last: this is what wires the registered asset views up for
        // impression and click reporting.
        adView.setNativeAd(nativeAd)
        return adView
    }

    private fun TextView.applyOptionalText(value: String?) {
        if (value.isNullOrEmpty()) {
            visibility = View.GONE
        } else {
            text = value
            visibility = View.VISIBLE
        }
    }
}

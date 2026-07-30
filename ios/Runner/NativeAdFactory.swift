import Foundation
import GoogleMobileAds
import UIKit
import google_mobile_ads

/// Builds the full-bleed native ad view shown inside the Snaps feed — the iOS
/// counterpart of `SnapNativeAdFactory` in android/.../NativeAdFactory.kt.
///
/// Registered under the id "snapNativeAdFactory" in AppDelegate; the Dart side
/// requests it by that same id (see NativeAdReelCard).
///
/// The hierarchy is built in code rather than from a XIB deliberately: a XIB
/// would need its own Xcode project registration and resource-bundle handling,
/// and this layout is simple enough that code keeps it in one reviewable place
/// alongside the asset wiring it depends on.
///
/// Class names here are the Swift-facing ones from Google Mobile Ads SDK 12+
/// (`NativeAd`/`NativeAdView`, formerly `GADNativeAd`/`GADNativeAdView`) —
/// the plugin pins `Google-Mobile-Ads-SDK ~> 13.2.0`.
class SnapNativeAdFactory: NSObject, FLTNativeAdFactory {

    func createNativeAd(
        _ nativeAd: NativeAd,
        customOptions: [AnyHashable: Any]? = nil
    ) -> NativeAdView? {
        let adView = NativeAdView()
        adView.backgroundColor = .black

        // MARK: Media — fills the slot like a Snap video.
        let mediaView = MediaView()
        mediaView.contentMode = .scaleAspectFill
        mediaView.clipsToBounds = true
        mediaView.translatesAutoresizingMaskIntoConstraints = false
        adView.addSubview(mediaView)
        adView.mediaView = mediaView

        // MARK: Bottom scrim so caption text stays legible over bright creative.
        let scrim = GradientView()
        scrim.translatesAutoresizingMaskIntoConstraints = false
        adView.addSubview(scrim)

        // MARK: The mandatory "Ad" disclosure chip — never conditionally hidden.
        let attribution = UILabel()
        attribution.text = " Ad "
        attribution.font = .boldSystemFont(ofSize: 11)
        attribution.textColor = .white
        // Brand orange (#C2410C), matching brand.css --theme-color.
        attribution.backgroundColor = UIColor(
            red: 194 / 255, green: 65 / 255, blue: 12 / 255, alpha: 1)
        attribution.layer.cornerRadius = 4
        attribution.clipsToBounds = true
        attribution.translatesAutoresizingMaskIntoConstraints = false
        adView.addSubview(attribution)

        // MARK: Caption block.
        let iconView = UIImageView()
        iconView.contentMode = .scaleAspectFill
        iconView.clipsToBounds = true
        iconView.layer.cornerRadius = 18
        iconView.translatesAutoresizingMaskIntoConstraints = false
        iconView.widthAnchor.constraint(equalToConstant: 36).isActive = true
        iconView.heightAnchor.constraint(equalToConstant: 36).isActive = true

        let advertiserLabel = UILabel()
        advertiserLabel.font = .boldSystemFont(ofSize: 14)
        advertiserLabel.textColor = .white

        let advertiserRow = UIStackView(arrangedSubviews: [iconView, advertiserLabel])
        advertiserRow.axis = .horizontal
        advertiserRow.spacing = 10
        advertiserRow.alignment = .center

        let headlineLabel = UILabel()
        headlineLabel.font = .boldSystemFont(ofSize: 16)
        headlineLabel.textColor = .white
        headlineLabel.numberOfLines = 2

        let bodyLabel = UILabel()
        bodyLabel.font = .systemFont(ofSize: 13)
        bodyLabel.textColor = UIColor(white: 0.9, alpha: 1)
        bodyLabel.numberOfLines = 2

        let ctaButton = UIButton(type: .system)
        ctaButton.titleLabel?.font = .boldSystemFont(ofSize: 15)
        ctaButton.setTitleColor(.white, for: .normal)
        ctaButton.backgroundColor = UIColor(
            red: 194 / 255, green: 65 / 255, blue: 12 / 255, alpha: 1)
        ctaButton.layer.cornerRadius = 10
        // The SDK handles the tap through the registered callToActionView; a
        // target of our own would swallow it and break click reporting.
        ctaButton.isUserInteractionEnabled = false
        ctaButton.heightAnchor.constraint(equalToConstant: 44).isActive = true

        let captionStack = UIStackView(arrangedSubviews: [
            advertiserRow, headlineLabel, bodyLabel, ctaButton,
        ])
        captionStack.axis = .vertical
        captionStack.spacing = 8
        captionStack.translatesAutoresizingMaskIntoConstraints = false
        adView.addSubview(captionStack)

        NSLayoutConstraint.activate([
            mediaView.topAnchor.constraint(equalTo: adView.topAnchor),
            mediaView.bottomAnchor.constraint(equalTo: adView.bottomAnchor),
            mediaView.leadingAnchor.constraint(equalTo: adView.leadingAnchor),
            mediaView.trailingAnchor.constraint(equalTo: adView.trailingAnchor),

            scrim.leadingAnchor.constraint(equalTo: adView.leadingAnchor),
            scrim.trailingAnchor.constraint(equalTo: adView.trailingAnchor),
            scrim.bottomAnchor.constraint(equalTo: adView.bottomAnchor),
            scrim.heightAnchor.constraint(equalToConstant: 260),

            attribution.topAnchor.constraint(
                equalTo: adView.safeAreaLayoutGuide.topAnchor, constant: 16),
            attribution.leadingAnchor.constraint(equalTo: adView.leadingAnchor, constant: 16),

            captionStack.leadingAnchor.constraint(equalTo: adView.leadingAnchor, constant: 16),
            captionStack.trailingAnchor.constraint(equalTo: adView.trailingAnchor, constant: -16),
            captionStack.bottomAnchor.constraint(
                equalTo: adView.safeAreaLayoutGuide.bottomAnchor, constant: -28),
        ])

        // MARK: Asset population.
        // Everything except the headline is optional in the Native Ads API; an
        // unpopulated asset left visible renders as a blank gap.
        headlineLabel.text = nativeAd.headline
        adView.headlineView = headlineLabel

        bodyLabel.text = nativeAd.body
        bodyLabel.isHidden = (nativeAd.body?.isEmpty ?? true)
        adView.bodyView = bodyLabel

        ctaButton.setTitle(nativeAd.callToAction, for: .normal)
        ctaButton.isHidden = (nativeAd.callToAction?.isEmpty ?? true)
        adView.callToActionView = ctaButton

        let advertiserText = nativeAd.advertiser ?? nativeAd.store
        advertiserLabel.text = advertiserText
        adView.advertiserView = advertiserLabel

        iconView.image = nativeAd.icon?.image
        iconView.isHidden = (nativeAd.icon?.image == nil)
        adView.iconView = iconView

        advertiserRow.isHidden = advertiserLabel.isHidden && iconView.isHidden

        // Must be last: this wires the registered asset views up for impression
        // and click reporting.
        adView.nativeAd = nativeAd
        return adView
    }
}

/// Bottom-up black gradient. UIKit has no built-in gradient view, and a
/// CAGradientLayer on a plain UIView needs a layout hook to track bounds.
private class GradientView: UIView {
    private let gradient = CAGradientLayer()

    override init(frame: CGRect) {
        super.init(frame: frame)
        gradient.colors = [
            UIColor.black.withAlphaComponent(0).cgColor,
            UIColor.black.withAlphaComponent(0.8).cgColor,
        ]
        layer.addSublayer(gradient)
        isUserInteractionEnabled = false
    }

    required init?(coder: NSCoder) {
        fatalError("init(coder:) has not been implemented")
    }

    override func layoutSubviews() {
        super.layoutSubviews()
        gradient.frame = bounds
    }
}

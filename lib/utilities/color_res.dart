import 'package:flutter/material.dart';

/// Brand palette.
///
/// One saturated family — a burnt-orange ramp — carries every brand surface:
/// primary CTAs, links, active tabs, progress. Everything supporting it is a
/// warm neutral, so the accent stays the only thing on screen competing for
/// attention. That single-brand-colour discipline is what reads as
/// "professional" in TikTok / Facebook / WhatsApp.
///
/// Ratios in comments are contrast against [whitePure].
class ColorRes {
  static const Color blackPure = Color(0xFF000000);
  static const Color whitePure = Color(0xFFFFFFFF);

  /// Gradient start — ember. Large fills only (buttons, headers), 3.8:1.
  static const Color themeGradient1 = Color(0xFFE05514);

  /// Gradient end — deep burnt, 7.3:1.
  static const Color themeGradient2 = Color(0xFF9A3412);

  /// Solid brand. The one accent for icons, links and body-size text — 5.2:1,
  /// so it passes AA where the gradient ends alone would not.
  static const Color themeAccentSolid = Color(0xFFC2410C);

  /// Dark chrome: sheets, live overlays, scrims. Warm charcoal rather than a
  /// blue-black, so it sits under the orange instead of fighting it.
  static const Color themeColor = Color(0xFF17120F);

  static const Color textDarkGrey = Color(0xFF292524); // 15.2:1
  static const Color textLightGrey = Color(0xFF78716C); // 4.8:1

  /// Pending / warning. Amber, not orange — it has to stay distinguishable
  /// from the burnt-orange brand colour when both sit in the same status list.
  static const Color orange = Color(0xFFF59E0B);
  static const Color green = Color(0xFF15803D);
  static const Color green1 = Color(0xFF22C55E);
  static const Color likeRed = Color(0xFFFF2D55);

  /// In-progress status. The only blue left in the product palette.
  static const Color infoBlue = Color(0xFF2563EB);
  static const Color textStoryBgGradient2 = Color(0xFFFF5757);
  static const Color battleProgressColor = Color(0xFF2CC3FF);

  static const Color bgLightGrey = Color(0xFFFAF9F8);
  static const Color bgGrey = Color(0xFFEDEAE7);
  static const Color bgMediumGrey = Color(0xFFF5F3F1);
  static const Color disabledGrey = Color(0xFFD6D3D1);
}

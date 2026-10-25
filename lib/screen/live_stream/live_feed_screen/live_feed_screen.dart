import 'dart:math' as math;
import 'dart:ui';

import 'package:figma_squircle_updated/figma_squircle.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:shortzz/common/extensions/common_extension.dart';
import 'package:shortzz/common/extensions/string_extension.dart';
import 'package:shortzz/common/widget/custom_image.dart';
import 'package:shortzz/common/widget/full_name_with_blue_tick.dart';
import 'package:shortzz/common/widget/loader_widget.dart';
import 'package:shortzz/common/widget/top_bar_actions.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/model/livestream/app_user.dart';
import 'package:shortzz/model/livestream/livestream.dart';
import 'package:shortzz/screen/live_stream/live_feed_screen/live_feed_screen_controller.dart';
import 'package:shortzz/screen/live_stream/livestream_screen/audience/widget/livestream_audience_top_view.dart';
import 'package:shortzz/screen/live_stream/livestream_screen/livestream_screen_controller.dart';
import 'package:shortzz/screen/live_stream/livestream_screen/view/battle_view.dart';
import 'package:shortzz/screen/live_stream/livestream_screen/view/live_stream_bottom_view.dart';
import 'package:shortzz/screen/live_stream/livestream_screen/view/livestream_view.dart';
import 'package:shortzz/screen/live_stream/livestream_screen/widget/battle_start_countdown_overlay.dart';
import 'package:shortzz/utilities/asset_res.dart';
import 'package:shortzz/utilities/color_res.dart';
import 'package:shortzz/utilities/text_style_custom.dart';
import 'package:shortzz/utilities/theme_res.dart';

/// The Live tab: the live rooms one at a time, full screen, TikTok style.
/// The room on screen is live — its video, comments, gifts and likes — and
/// swiping up moves to the next one.
class LiveFeedScreen extends StatelessWidget {
  const LiveFeedScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final controller = Get.put(LiveFeedScreenController());
    controller.dashboardRoute ??= ModalRoute.settingsOf(context)?.name;
    return LayoutBuilder(builder: (context, constraints) {
      // The dashboard shrinks for the keyboard. The rooms keep their full
      // height, so the video does not jump while you type a comment; the
      // room's controls are lifted above the keyboard instead.
      final double fullHeight = MediaQuery.sizeOf(context).height;
      final double height =
          constraints.hasBoundedHeight ? constraints.maxHeight : fullHeight;
      final double keyboardLift = math.max(0, fullHeight - height);
      // Fills the tab whatever the constraints. Under the tab stack's loose
      // ones the old Stack took the size of its top bar alone, and the tab
      // was a white page with the bar on it.
      return OverflowBox(
        alignment: Alignment.topCenter,
        minHeight: fullHeight,
        maxHeight: fullHeight,
        child: ColoredBox(
          color: Colors.black,
          child: Stack(
            fit: StackFit.expand,
            children: [
              Obx(() {
                if (controller.streams.isEmpty) {
                  return controller.hasLoaded.value
                      ? _NoOneLive(controller: controller)
                      : const LoaderWidget(color: Colors.white);
                }
                return PageView.builder(
                  controller: controller.pageController,
                  scrollDirection: Axis.vertical,
                  physics: controller.isCoHosting.value
                      ? const NeverScrollableScrollPhysics()
                      : null,
                  itemCount: controller.streams.length,
                  onPageChanged: controller.onPageChanged,
                  itemBuilder: (context, index) => _LiveRoomPage(
                    controller: controller,
                    stream: controller.streams[index],
                    index: index,
                    keyboardLift: keyboardLift,
                  ),
                );
              }),
              Positioned(
                  top: 0,
                  left: 0,
                  right: 0,
                  child: _TopBar(controller: controller)),
            ],
          ),
        ),
      );
    });
  }
}

/// Live, then report, search, the activity bell and Go Live; a soft shade
/// behind it keeps it readable over a bright video, as on Snaps.
class _TopBar extends StatelessWidget {
  static const double height = 52;

  final LiveFeedScreenController controller;

  const _TopBar({required this.controller});

  @override
  Widget build(BuildContext context) {
    return Stack(
      children: [
        // Decoration only: taps go through to the room underneath.
        const Positioned.fill(
          child: IgnorePointer(
            child: DecoratedBox(
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment.topCenter,
                  end: Alignment.bottomCenter,
                  colors: [Color(0x99000000), Color(0x00000000)],
                ),
              ),
            ),
          ),
        ),
        SafeArea(
          bottom: false,
          child: SizedBox(
            height: height,
            child: Padding(
              padding: const EdgeInsets.fromLTRB(15, 0, 10, 0),
              child: Row(
                children: [
                  Image.asset(AssetRes.icLive,
                      color: whitePure(context), height: 24, width: 24),
                  const SizedBox(width: 6),
                  Text(LKey.live.tr.toUpperCase(),
                      style: TextStyleCustom.unboundedMedium500(
                          color: whitePure(context), fontSize: 15)),
                  const Spacer(),
                  Obx(() {
                    final Livestream? stream = controller.currentStream;
                    if (stream == null ||
                        LiveFeedScreenController.isMine(stream) ||
                        controller.endedRooms.contains(stream.roomID)) {
                      return const SizedBox();
                    }
                    return TopBarIcon(
                      onTap: controller.reportCurrentHost,
                      child:
                          Image.asset(AssetRes.icAlert, width: 24, height: 24),
                    );
                  }),
                  TopBarActions(color: whitePure(context)),
                  const SizedBox(width: 6),
                  InkWell(
                    onTap: controller.onGoLive,
                    child: Container(
                      height: 32,
                      padding: const EdgeInsets.symmetric(horizontal: 10),
                      decoration: ShapeDecoration(
                          color: Colors.white,
                          shape: SmoothRectangleBorder(
                              borderRadius: SmoothBorderRadius(
                                  cornerRadius: 30, cornerSmoothing: 1))),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Image.asset(AssetRes.icLive_1,
                              color: Colors.black, width: 22, height: 22),
                          const SizedBox(width: 2),
                          Text(LKey.goLive.tr,
                              style: TextStyleCustom.unboundedRegular400(
                                  color: Colors.black, fontSize: 12)),
                        ],
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ],
    );
  }
}

class _LiveRoomPage extends StatelessWidget {
  final LiveFeedScreenController controller;
  final Livestream stream;
  final int index;
  final double keyboardLift;

  const _LiveRoomPage(
      {required this.controller,
      required this.stream,
      required this.index,
      required this.keyboardLift});

  @override
  Widget build(BuildContext context) {
    final double bottomInset = MediaQuery.paddingOf(context).bottom;
    return Obx(() {
      final AppUser? host =
          stream.getHostUser(controller.appUserCacheController.users);
      final String? roomId = stream.roomID;
      final bool isCurrent = controller.currentIndex.value == index;
      final bool ended = controller.endedRooms.contains(roomId);
      final bool failed = controller.failedRooms.contains(roomId);
      final bool mine = LiveFeedScreenController.isMine(stream);
      final LivestreamScreenController? live = controller.roomFor(roomId);

      return Stack(
        fit: StackFit.expand,
        children: [
          _HostBackdrop(host: host),
          if (live != null && !ended)
            _EmbeddedRoom(room: live, keyboardLift: keyboardLift)
          else ...[
            if (isCurrent && !ended && !failed && !mine) const _Connecting(),
            const _BottomShade(),
            Positioned(
              left: 15,
              right: 15,
              bottom: bottomInset + 20,
              child: _RoomInfo(
                stream: stream,
                host: host,
                action: mine && !ended
                    ? _PillButton(
                        label: LKey.backToYourLive.tr,
                        color: ColorRes.likeRed,
                        textColor: Colors.white,
                        onTap: () => controller.openMyLive(stream))
                    : null,
              ),
            ),
          ],
          if (ended)
            _Notice(
              title: LKey.thisLiveHasEnded.tr,
              subtitle: index < controller.streams.length - 1
                  ? LKey.swipeUpForNextLive.tr
                  : null,
            )
          else if (failed)
            _Notice(
              title: LKey.liveVideoUnavailable.tr,
              action: _PillButton(
                  label: LKey.retry.tr,
                  color: Colors.white,
                  textColor: Colors.black,
                  onTap: () => controller.retry(roomId)),
            ),
        ],
      );
    });
  }
}

/// The joined room, laid out as its own screen is but under the tab's bar
/// and above the floating bottom bar, which the dashboard passes down as
/// the bottom inset.
class _EmbeddedRoom extends StatelessWidget {
  final LivestreamScreenController room;
  final double keyboardLift;

  const _EmbeddedRoom({required this.room, required this.keyboardLift});

  @override
  Widget build(BuildContext context) {
    return Stack(
      fit: StackFit.expand,
      children: [
        Obx(() {
          switch (room.liveData.value.type) {
            case null:
            case LivestreamType.livestream:
              // Until the host's camera arrives: the same "Connecting…" as
              // before the room was joined, over the host's photo.
              if (room.streamViews.isEmpty) return const _Connecting();
              return LivestreamView(
                  streamViews: room.streamViews, controller: room);
            case LivestreamType.battle:
              return BattleView(
                isAudience: true,
                controller: room,
                // Below the tab's bar and the host's row.
                margin: const EdgeInsets.only(top: _TopBar.height + 58),
              );
          }
        }),
        Padding(
          padding: EdgeInsets.only(bottom: keyboardLift),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Padding(
                padding: EdgeInsets.only(
                    top: MediaQuery.paddingOf(context).top + _TopBar.height),
                child: LiveStreamAudienceTopView(
                    isAudience: true, controller: room, embedded: true),
              ),
              LiveStreamBottomView(isAudience: true, controller: room),
            ],
          ),
        ),
        Obx(() {
          final Livestream stream = room.liveData.value;
          if (stream.battleType == BattleType.waiting) {
            return BattleStartCountdownOverlay(isHost: false, stream: stream);
          }
          return const SizedBox();
        }),
      ],
    );
  }
}

/// The host's photo, blurred: behind the video while it connects, and the
/// whole page for the rooms either side.
class _HostBackdrop extends StatelessWidget {
  final AppUser? host;

  const _HostBackdrop({required this.host});

  @override
  Widget build(BuildContext context) {
    return Stack(
      fit: StackFit.expand,
      children: [
        CustomImage(
          size: Size(Get.width, Get.height),
          radius: 0,
          image: host?.profile?.addBaseURL(),
          fullName: host?.fullname,
        ),
        ClipRect(
          child: BackdropFilter(
            filter: ImageFilter.blur(sigmaX: 40, sigmaY: 40),
            child: ColoredBox(color: Colors.black.withValues(alpha: .45)),
          ),
        ),
      ],
    );
  }
}

class _Connecting extends StatelessWidget {
  const _Connecting();

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const SizedBox(
              width: 28,
              height: 28,
              child: CircularProgressIndicator(
                  strokeWidth: 2.5, color: Colors.white)),
          const SizedBox(height: 10),
          Text(LKey.connectingToLive.tr,
              style: TextStyleCustom.outFitRegular400(
                  color: Colors.white, fontSize: 14)),
        ],
      ),
    );
  }
}

/// Keeps the host's details readable over a bright photo.
class _BottomShade extends StatelessWidget {
  const _BottomShade();

  @override
  Widget build(BuildContext context) {
    return const Positioned(
      left: 0,
      right: 0,
      bottom: 0,
      height: 300,
      child: IgnorePointer(
        child: DecoratedBox(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              begin: Alignment.topCenter,
              end: Alignment.bottomCenter,
              colors: [Colors.transparent, Color(0xCC000000)],
            ),
          ),
        ),
      ),
    );
  }
}

/// Over a room that ended, or whose video could not be reached.
class _Notice extends StatelessWidget {
  final String title;
  final String? subtitle;
  final Widget? action;

  const _Notice({required this.title, this.subtitle, this.action});

  @override
  Widget build(BuildContext context) {
    final String? subtitle = this.subtitle;
    final Widget? action = this.action;
    return ColoredBox(
      color: Colors.black.withValues(alpha: .65),
      child: Center(
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 35),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(title,
                  textAlign: TextAlign.center,
                  style: TextStyleCustom.outFitSemiBold600(
                      color: Colors.white, fontSize: 20)),
              if (subtitle != null) ...[
                const SizedBox(height: 6),
                Text(subtitle,
                    textAlign: TextAlign.center,
                    style: TextStyleCustom.outFitLight300(
                        color: Colors.white, fontSize: 15)),
              ],
              if (action != null) ...[
                const SizedBox(height: 18),
                action,
              ],
            ],
          ),
        ),
      ),
    );
  }
}

class _PillButton extends StatelessWidget {
  final String label;
  final Color color;
  final Color textColor;
  final VoidCallback onTap;

  const _PillButton(
      {required this.label,
      required this.color,
      required this.textColor,
      required this.onTap});

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 44,
      child: ElevatedButton(
        onPressed: onTap,
        style: ElevatedButton.styleFrom(
          backgroundColor: color,
          foregroundColor: textColor,
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(30)),
          elevation: 0,
          padding: const EdgeInsets.symmetric(horizontal: 24),
        ),
        child: Text(label,
            style: TextStyleCustom.outFitSemiBold600(
                color: textColor, fontSize: 15)),
      ),
    );
  }
}

/// Who is live, for a room that is not joined: the ones either side while
/// swiping, and your own.
class _RoomInfo extends StatelessWidget {
  final Livestream stream;
  final AppUser? host;
  final Widget? action;

  const _RoomInfo({required this.stream, required this.host, this.action});

  @override
  Widget build(BuildContext context) {
    final int viewers =
        (stream.watchingCount ?? 0) < 0 ? 0 : stream.watchingCount ?? 0;
    final String description = stream.description?.trim() ?? '';
    final Widget? action = this.action;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        Row(
          children: [
            CustomImage(
              size: const Size(44, 44),
              image: host?.profile?.addBaseURL(),
              fullName: host?.fullname,
              strokeWidth: 2,
              strokeColor: Colors.white,
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  FullNameWithBlueTick(
                    username: host?.username ?? '',
                    isVerify: host?.isVerify,
                    iconSize: 16,
                    icon: AssetRes.icVerifiedWhite,
                    style: TextStyleCustom.outFitSemiBold600(
                        color: Colors.white, fontSize: 16),
                  ),
                  const SizedBox(height: 3),
                  Row(
                    children: [
                      Container(
                        padding: const EdgeInsets.symmetric(
                            horizontal: 6, vertical: 1),
                        decoration: BoxDecoration(
                            color: ColorRes.likeRed,
                            borderRadius: BorderRadius.circular(4)),
                        child: Text(LKey.live.tr.toUpperCase(),
                            style: TextStyleCustom.outFitSemiBold600(
                                color: Colors.white, fontSize: 11)),
                      ),
                      const SizedBox(width: 8),
                      Image.asset(AssetRes.icEye,
                          color: Colors.white, width: 15, height: 15),
                      const SizedBox(width: 4),
                      Text('${viewers.numberFormat} ${LKey.viewers.tr}',
                          style: TextStyleCustom.outFitRegular400(
                              color: Colors.white, fontSize: 13)),
                    ],
                  ),
                ],
              ),
            ),
          ],
        ),
        if (description.isNotEmpty) ...[
          const SizedBox(height: 10),
          Text(description,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: TextStyleCustom.outFitRegular400(
                  color: Colors.white, fontSize: 15)),
        ],
        if (action != null) ...[
          const SizedBox(height: 14),
          SizedBox(width: double.infinity, child: action),
        ],
      ],
    );
  }
}

/// Nobody is broadcasting: say so plainly and offer to go live, rather than
/// showing rooms that have already ended.
class _NoOneLive extends StatelessWidget {
  final LiveFeedScreenController controller;

  const _NoOneLive({required this.controller});

  @override
  Widget build(BuildContext context) {
    final EdgeInsets insets = MediaQuery.paddingOf(context);
    return RefreshIndicator(
      onRefresh: controller.fetchStreams,
      edgeOffset: insets.top + _TopBar.height,
      child: LayoutBuilder(
        builder: (context, constraints) => SingleChildScrollView(
          physics: const AlwaysScrollableScrollPhysics(),
          child: ConstrainedBox(
            constraints: BoxConstraints(minHeight: constraints.maxHeight),
            child: Padding(
              // Centred between the top bar and the bottom bar.
              padding: EdgeInsets.fromLTRB(
                  35, insets.top + _TopBar.height, 35, insets.bottom),
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Container(
                    width: 84,
                    height: 84,
                    decoration: BoxDecoration(
                        shape: BoxShape.circle,
                        color: Colors.white.withValues(alpha: .08)),
                    alignment: Alignment.center,
                    child: Image.asset(AssetRes.icLive,
                        color: Colors.white, width: 42, height: 42),
                  ),
                  const SizedBox(height: 18),
                  Text(LKey.noOneIsLiveTitle.tr,
                      textAlign: TextAlign.center,
                      style: TextStyleCustom.outFitSemiBold600(
                          color: Colors.white, fontSize: 20)),
                  const SizedBox(height: 8),
                  Text(LKey.noOneIsLiveDescription.tr,
                      textAlign: TextAlign.center,
                      style: TextStyleCustom.outFitLight300(
                          color: Colors.white70, fontSize: 15)),
                  const SizedBox(height: 22),
                  _PillButton(
                      label: LKey.goLive.tr,
                      color: Colors.white,
                      textColor: Colors.black,
                      onTap: controller.onGoLive),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

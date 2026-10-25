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
import 'package:shortzz/utilities/asset_res.dart';
import 'package:shortzz/utilities/color_res.dart';
import 'package:shortzz/utilities/text_style_custom.dart';
import 'package:shortzz/utilities/theme_res.dart';

/// The Live tab: the live rooms one at a time, full screen and playing,
/// TikTok style. Swipe up for the next room, tap to join it.
class LiveFeedScreen extends StatelessWidget {
  const LiveFeedScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final controller = Get.put(LiveFeedScreenController());
    return ColoredBox(
      color: Colors.black,
      child: Stack(
        children: [
          Positioned.fill(
            child: Obx(() {
              if (controller.streams.isEmpty) {
                return controller.hasLoaded.value
                    ? _NoOneLive(controller: controller)
                    : const LoaderWidget();
              }
              return PageView.builder(
                controller: controller.pageController,
                scrollDirection: Axis.vertical,
                itemCount: controller.streams.length,
                onPageChanged: controller.onPageChanged,
                itemBuilder: (context, index) => _LiveRoomPage(
                  controller: controller,
                  stream: controller.streams[index],
                  index: index,
                ),
              );
            }),
          ),
          _TopBar(controller: controller),
        ],
      ),
    );
  }
}

class _TopBar extends StatelessWidget {
  final LiveFeedScreenController controller;

  const _TopBar({required this.controller});

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      bottom: false,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(15, 10, 10, 0),
        child: Row(
          children: [
            Image.asset(AssetRes.icLive,
                color: whitePure(context), height: 24, width: 24),
            const SizedBox(width: 6),
            Text(LKey.live.tr.toUpperCase(),
                style: TextStyleCustom.unboundedMedium500(
                    color: whitePure(context), fontSize: 15)),
            const Spacer(),
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
    );
  }
}

class _LiveRoomPage extends StatelessWidget {
  final LiveFeedScreenController controller;
  final Livestream stream;
  final int index;

  const _LiveRoomPage(
      {required this.controller, required this.stream, required this.index});

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTap: controller.joinCurrent,
      child: Obx(() {
        final AppUser? host =
            stream.getHostUser(controller.appUserCacheController.users);
        final bool isCurrent = controller.currentIndex.value == index;
        final bool ended = controller.endedRooms.contains(stream.roomID);
        final Widget? video = isCurrent ? controller.previewView.value : null;
        final bool rendering = isCurrent && controller.isRendering.value;

        return Stack(
          fit: StackFit.expand,
          children: [
            _HostBackdrop(host: host),
            if (video != null && !ended)
              AnimatedOpacity(
                opacity: rendering ? 1 : 0,
                duration: const Duration(milliseconds: 250),
                child: video,
              ),
            if (!rendering && !ended)
              Center(
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
              ),
            // Keeps the text readable over a bright video.
            const Positioned(
              left: 0,
              right: 0,
              bottom: 0,
              height: 260,
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
            Positioned(
              left: 15,
              right: 15,
              bottom: 20,
              child: _RoomInfo(stream: stream, host: host, enabled: !ended,
                  onJoin: controller.joinCurrent),
            ),
            if (ended)
              ColoredBox(
                color: Colors.black.withValues(alpha: .65),
                child: Center(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(LKey.thisLiveHasEnded.tr,
                          style: TextStyleCustom.outFitSemiBold600(
                              color: Colors.white, fontSize: 20)),
                      if (index < controller.streams.length - 1) ...[
                        const SizedBox(height: 6),
                        Text(LKey.swipeUpForNextLive.tr,
                            style: TextStyleCustom.outFitLight300(
                                color: Colors.white, fontSize: 15)),
                      ],
                    ],
                  ),
                ),
              ),
          ],
        );
      }),
    );
  }
}

/// The host's photo, blurred, behind the video while it connects.
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

class _RoomInfo extends StatelessWidget {
  final Livestream stream;
  final AppUser? host;
  final bool enabled;
  final VoidCallback onJoin;

  const _RoomInfo(
      {required this.stream,
      required this.host,
      required this.enabled,
      required this.onJoin});

  @override
  Widget build(BuildContext context) {
    final int viewers = (stream.watchingCount ?? 0) < 0 ? 0 : stream.watchingCount ?? 0;
    final String description = stream.description?.trim() ?? '';
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
        const SizedBox(height: 14),
        SizedBox(
          width: double.infinity,
          height: 46,
          child: ElevatedButton(
            onPressed: enabled ? onJoin : null,
            style: ElevatedButton.styleFrom(
              backgroundColor: ColorRes.likeRed,
              disabledBackgroundColor: Colors.white24,
              foregroundColor: Colors.white,
              shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(30)),
              elevation: 0,
            ),
            child: Text(LKey.joinLive.tr,
                style: TextStyleCustom.outFitSemiBold600(
                    color: Colors.white, fontSize: 16)),
          ),
        ),
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
    return RefreshIndicator(
      onRefresh: controller.fetchStreams,
      child: LayoutBuilder(
        builder: (context, constraints) => SingleChildScrollView(
          physics: const AlwaysScrollableScrollPhysics(),
          child: ConstrainedBox(
            constraints: BoxConstraints(minHeight: constraints.maxHeight),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 35),
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
                  SizedBox(
                    height: 44,
                    child: ElevatedButton(
                      onPressed: controller.onGoLive,
                      style: ElevatedButton.styleFrom(
                        backgroundColor: Colors.white,
                        foregroundColor: Colors.black,
                        shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(30)),
                        elevation: 0,
                        padding: const EdgeInsets.symmetric(horizontal: 24),
                      ),
                      child: Text(LKey.goLive.tr,
                          style: TextStyleCustom.outFitSemiBold600(
                              color: Colors.black, fontSize: 15)),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

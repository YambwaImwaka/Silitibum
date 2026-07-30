import 'package:figma_squircle_updated/figma_squircle.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:shortzz/common/extensions/duration_extension.dart';
import 'package:shortzz/common/extensions/string_extension.dart';
import 'package:shortzz/common/service/media/video_cache_service.dart';
import 'package:shortzz/screen/dashboard_screen/dashboard_screen_controller.dart';
import 'package:shortzz/screen/reels_screen/reel/reel_page_controller.dart';
import 'package:shortzz/utilities/text_style_custom.dart';
import 'package:shortzz/utilities/theme_res.dart';
import 'package:video_player/video_player.dart';

class ReelSeekBar extends StatefulWidget {
  final VideoPlayerController? videoController;
  final ReelController controller;

  const ReelSeekBar({super.key, required this.videoController, required this.controller});

  @override
  State<ReelSeekBar> createState() => _ReelSeekBarState();
}

class _ReelSeekBarState extends State<ReelSeekBar> {
  late final GlobalKey sliderKey = GlobalKey();
  late final VideoPlayerController? _mainController = widget.videoController;
  VideoPlayerController? _overlayController;

  OverlayEntry? _overlayEntry;
  final ValueNotifier<Offset?> _overlayOffsetNotifier = ValueNotifier(null);
  Duration _currentPosition = Duration.zero;
  bool _isOverlayInitialized = false;
  bool _isOverlayVisible = false;
  final dashboardController = Get.find<DashboardScreenController>();
  bool isVideoPlaying = true;
  // initState used to register `_mainController.addListener(...)` which, on
  // *every* player tick (many times a second, for every mounted reel page),
  // awaited `controller.position` — a MethodChannel round-trip to ExoPlayer —
  // and then called setState, rebuilding this whole subtree.
  //
  // None of it was needed: `_currentPosition` is only ever painted inside the
  // scrub overlay, which supplies its own position via _updateOverlayPosition,
  // and the slider already reads the position synchronously from the
  // ValueListenableBuilder below. Removing it takes a per-frame platform hop
  // and a setState off the playback path.

  void _removeOverlay() {
    _overlayEntry?.remove();
    _overlayEntry?.dispose();
    _overlayEntry = null;
    _isOverlayVisible = false; // 👈 reset here

    if (_isOverlayInitialized && _overlayController != null) {
      _overlayController!.removeListener(_updateOverlayPosition);
      _overlayController!.dispose();
      _overlayController = null;
      _isOverlayInitialized = false;
    }

    _overlayOffsetNotifier.value = null;
  }

  void _updateOverlayPosition() {
    // Read the position the listener notification already carries instead of
    // awaiting a platform-channel call for a value we were just handed.
    final pos = _overlayController?.value.position;
    if (pos != null && mounted && pos != _currentPosition) {
      setState(() => _currentPosition = pos);
    }
  }

  void _updateOverlayLocation(Offset globalOffset) {
    _overlayOffsetNotifier.value = globalOffset;
  }

  Future<void> _createOverlay() async {
    if (_isOverlayVisible) return; // 👈 Prevent duplicate overlays

    _isOverlayVisible = true;
    _removeOverlay();

    String url = widget.controller.reelData.value.video?.addBaseURL() ?? '';
    if (url.isEmpty) return;

    // Start the preview where the reel currently is, so the thumbnail is not
    // blank for the moment before the scrub controller reports a position.
    _currentPosition = _mainController?.value.position ?? Duration.zero;

    // Prefer the already-downloaded file. Scrubbing used to open a *second*
    // network stream of a video we usually have on disk, competing with the
    // reel still buffering behind it.
    final cachedFile = await VideoCacheService.getCachedFile(url);
    VideoPlayerController newController = cachedFile != null
        ? VideoPlayerController.file(cachedFile)
        : VideoPlayerController.networkUrl(Uri.parse(url));
    await newController.initialize();
    newController.addListener(_updateOverlayPosition);

    _overlayController = newController;
    _isOverlayInitialized = true;

    _overlayEntry = OverlayEntry(
      builder: (context) {
        if (!_isOverlayInitialized) return const SizedBox();

        return ValueListenableBuilder<Offset?>(
          valueListenable: _overlayOffsetNotifier,
          builder: (context, offset, _) {
            if (offset == null) return const SizedBox();

            final screenWidth = MediaQuery.of(context).size.width;
            final double dx = (offset.dx - 30).clamp(0, screenWidth - 100);
            bool isPostUploading = dashboardController.postProgress.value.uploadType != UploadType.none;
            final top = MediaQuery.of(context).size.height * 0.75 - (!isPostUploading ? 60 : 80);

            return Positioned(
              left: dx,
              top: top,
              child: Material(
                color: Colors.transparent,
                child: Stack(
                  alignment: Alignment.bottomCenter,
                  children: [
                    SizedBox(
                      width: 100,
                      height: 170,
                      child: ClipRRect(
                        borderRadius: SmoothBorderRadius(cornerRadius: 10, cornerSmoothing: 1),
                        child: VideoPlayer(_overlayController!),
                      ),
                    ),
                    Container(
                      width: 100,
                      height: 170,
                      decoration: ShapeDecoration(
                        shape: SmoothRectangleBorder(
                          borderRadius: SmoothBorderRadius(cornerRadius: 10, cornerSmoothing: 1),
                          side: BorderSide(
                            color: whitePure(context).withAlpha(50),
                            width: 1,
                          ),
                        ),
                      ),
                    ),
                    Padding(
                      padding: const EdgeInsets.only(bottom: 8),
                      child: Text(
                        _currentPosition.printDuration,
                        style: TextStyleCustom.outFitMedium500(
                          color: whitePure(context),
                          fontSize: 15,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            );
          },
        );
      },
    );
    WidgetsBinding.instance.addPostFrameCallback((timeStamp) {
      Overlay.of(context).insert(_overlayEntry!);
    });
  }

  @override
  void dispose() {
    _removeOverlay();
    _overlayOffsetNotifier.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final controller = _isOverlayInitialized ? _overlayController : _mainController;

    if (controller == null) return const SizedBox(height: 15);

    return ValueListenableBuilder<VideoPlayerValue>(
      valueListenable: controller,
      builder: (context, value, _) {
        final duration = value.duration.inMicroseconds.toDouble();
        final position = value.position.inMicroseconds.toDouble();

        return SliderTheme(
          data: SliderTheme.of(context).copyWith(
            trackHeight: 2,
            padding: EdgeInsets.zero,
            overlayShape: const RoundSliderOverlayShape(overlayRadius: 0),
            thumbShape: _InvisibleThumbShape(),
            trackShape: const RectangularSliderTrackShape(),
          ),
          child: Listener(
            onPointerMove: (event) => _updateOverlayLocation(event.position),
            child: Slider(
              key: sliderKey,
              value: position.clamp(0, duration),
              min: 0,
              max: duration,
              activeColor: textLightGrey(context),
              inactiveColor: textDarkGrey(context),
              onChangeStart: (value) {
                if (duration <= 0) return;
                _createOverlay();
                isVideoPlaying = widget.videoController?.value.isPlaying ?? true;
                _mainController?.pause();
              },
              onChangeEnd: (value) {
                if (duration <= 0) return;
                _removeOverlay();
                if (isVideoPlaying) {
                  _mainController?.play();
                }

                _mainController?.seekTo(Duration(microseconds: value.toInt()));
              },
              onChanged: (value) {
                if (duration <= 0) return;
                _overlayController?.seekTo(Duration(microseconds: value.toInt()));
              },
            ),
          ),
        );
      },
    );
  }
}

class _InvisibleThumbShape extends SliderComponentShape {
  @override
  Size getPreferredSize(bool isEnabled, bool isDiscrete) => const Size(15, 15);

  @override
  void paint(
    PaintingContext context,
    Offset center, {
    required Animation<double> activationAnimation,
    required Animation<double> enableAnimation,
    required bool isDiscrete,
    required TextPainter? labelPainter,
    required RenderBox parentBox,
    required SliderThemeData sliderTheme,
    required TextDirection textDirection,
    required double value,
    required double textScaleFactor,
    required Size sizeWithOverflow,
  }) {
    // No thumb to paint
  }

  bool hitTest(
    Offset thumbCenter,
    Offset touchPosition, {
    required Size sizeWithOverflow,
    required SliderThemeData sliderTheme,
    required TextDirection textDirection,
  }) {
    // Expand interactive area (e.g., 24x24)
    return (touchPosition - thumbCenter).distance <= 12;
  }
}

import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:shortzz/common/manager/live_recording_manager.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/utilities/text_style_custom.dart';
import 'package:shortzz/utilities/theme_res.dart';

/// Tells the host what is happening to the recording of the live they just
/// finished.
///
/// The upload keeps running while this screen is open, so the state is read
/// from LiveRecordingManager rather than passed in — the room controller that
/// started the recording has already been disposed by the time this builds.
class LiveRecordingStatus extends StatelessWidget {
  const LiveRecordingStatus({super.key});

  @override
  Widget build(BuildContext context) {
    final manager = LiveRecordingManager.instance;

    return Obx(() {
      final state = manager.state.value;
      if (state == LiveRecordingState.idle ||
          state == LiveRecordingState.recording) {
        return const SizedBox.shrink();
      }

      final (String label, IconData icon) = switch (state) {
        LiveRecordingState.uploading => (
            LKey.savingYourLive.tr,
            Icons.cloud_upload_outlined
          ),
        LiveRecordingState.saved => (
            LKey.liveSavedToProfile.tr,
            Icons.check_circle_outline
          ),
        _ => (LKey.liveCouldNotBeSaved.tr, Icons.error_outline),
      };

      return Padding(
        padding: const EdgeInsets.only(top: 14),
        child: Column(
          children: [
            Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(icon, size: 16, color: textLightGrey(context)),
                const SizedBox(width: 6),
                Text(label,
                    style: TextStyleCustom.outFitLight300(
                        fontSize: 14, color: textLightGrey(context))),
              ],
            ),
            if (state == LiveRecordingState.uploading) ...[
              const SizedBox(height: 8),
              SizedBox(
                width: 180,
                child: ClipRRect(
                  borderRadius: BorderRadius.circular(4),
                  child: LinearProgressIndicator(
                    value: manager.progress.value,
                    minHeight: 4,
                    backgroundColor: whitePure(context).withValues(alpha: .2),
                    color: whitePure(context),
                  ),
                ),
              ),
            ],
          ],
        ),
      );
    });
  }
}

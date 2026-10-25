import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:intl/intl.dart';
import 'package:shortzz/common/extensions/common_extension.dart';
import 'package:shortzz/common/extensions/string_extension.dart';
import 'package:shortzz/common/functions/json_parse.dart';
import 'package:shortzz/common/service/api/post_service.dart';
import 'package:shortzz/common/widget/custom_image.dart';
import 'package:shortzz/common/widget/full_name_with_blue_tick.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/model/general/settings_model.dart';
import 'package:shortzz/model/misc/activity_notification_model.dart';
import 'package:shortzz/screen/comment_sheet/helper/comment_helper.dart';
import 'package:shortzz/screen/notification_screen/notification_screen_controller.dart';
import 'package:shortzz/utilities/asset_res.dart';
import 'package:shortzz/utilities/text_style_custom.dart';
import 'package:shortzz/utilities/theme_res.dart';

/// One activity row. The whole row opens what it is about (see
/// [NotificationScreenController.onNotificationTap]); the avatar opens the
/// person. Rows newer than what was seen before the screen opened are tinted.
class ActivityNotificationPage extends StatelessWidget {
  final ActivityNotification data;
  final NotificationScreenController controller;

  const ActivityNotificationPage(
      {super.key, required this.data, required this.controller});

  @override
  Widget build(BuildContext context) {
    final postWidget = _buildPostOrGiftImage(context);
    final String when = _timeAgo(data.createdAt);

    return InkWell(
      onTap: () => controller.onNotificationTap(data),
      child: Container(
        color: controller.isUnread(data)
            ? themeAccentSolid(context).withValues(alpha: .07)
            : null,
        padding: const EdgeInsets.symmetric(horizontal: 15.0, vertical: 10),
        child: Row(
          spacing: 10,
          children: [
            CustomImage(
              onTap: () => controller.onUserTap(data.fromUser),
              size: const Size(42, 42),
              fit: BoxFit.cover,
              image: data.fromUser?.profilePhoto?.addBaseURL(),
              fullName: data.fromUser?.fullname,
            ),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  FullNameWithBlueTick(
                      username: data.fromUser?.username,
                      fontSize: 13,
                      isVerify: data.fromUser?.isVerify),
                  const SizedBox(height: 2),
                  Text(
                    _getNotificationText(),
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyleCustom.outFitRegular400(
                      fontSize: 14,
                      color: textLightGrey(context),
                    ),
                  ),
                  if (when.isNotEmpty) ...[
                    const SizedBox(height: 2),
                    Text(when,
                        style: TextStyleCustom.outFitLight300(
                            fontSize: 12, color: textLightGrey(context))),
                  ],
                ],
              ),
            ),
            if (postWidget != null) postWidget,
          ],
        ),
      ),
    );
  }

  /// Map notification type to text
  String _getNotificationText() {
    final commentDesc = data.data?.comment?.commentDescription ?? '';

    final replyCommentDesc =
        (data.data?.reply ?? data.data?.comment)?.commentDescription ?? '';

    switch (data.type) {
      case ActivityNotifyType.notifyLikePost:
        return LKey.activityLikedPost.tr;
      case ActivityNotifyType.notifyCommentPost:
        if (data.data?.comment?.type == CommentType.image) {
          return LKey.activityGIFComment.tr;
        }
        return LKey.activityCommentedPost
            .trParams({'comment_description': commentDesc});
      case ActivityNotifyType.notifyMentionPost:
        return LKey.notifyMentionedInPost.tr;
      case ActivityNotifyType.notifyMentionComment:
      case ActivityNotifyType.notifyReplyComment:
        return LKey.activityReplyingToComment.trParams({
          'username': data.fromUser?.username ?? '',
          'comment_description': replyCommentDesc,
        });
      case ActivityNotifyType.notifyMentionReply:
        return LKey.notifyReplyMentionedInComment
            .trParams({'comment_description': commentDesc});
      case ActivityNotifyType.notifyFollowUser:
        return LKey.notifyStartedFollowing.tr;
      case ActivityNotifyType.notifyGiftUser:
        return data.data?.post != null
            ? LKey.activitySentGiftOnPost.tr
            : LKey.activitySentGift.tr;
      case ActivityNotifyType.notifyNewPost:
        switch (data.data?.post?.postType) {
          case PostType.reel:
          case PostType.video:
            return LKey.notifyPostedNewVideo.tr;
          case PostType.image:
            return LKey.notifyPostedNewPhoto.tr;
          default:
            return LKey.notifyPostedNewPost.tr;
        }
      case ActivityNotifyType.none:
        return '';
    }
  }

  /// Builds image widget based on type
  Widget? _buildPostOrGiftImage(BuildContext context) {
    final type = data.type;
    final post = data.data?.post;

    if (type == ActivityNotifyType.notifyGiftUser) {
      return NotificationGiftIcon(gift: data.data?.gift);
    }

    if ([
          ActivityNotifyType.notifyLikePost,
          ActivityNotifyType.notifyCommentPost,
          ActivityNotifyType.notifyMentionPost,
          ActivityNotifyType.notifyMentionComment,
          ActivityNotifyType.notifyReplyComment,
          ActivityNotifyType.notifyMentionReply,
          ActivityNotifyType.notifyNewPost,
        ].contains(type) &&
        post != null &&
        post.postType != PostType.text) {
      return CustomImage(
          onTap: () => controller.onPostTap(data),
          image: (post.postType == PostType.image
                  ? post.images?.firstOrNull?.image
                  : post.thumbnail)
              ?.addBaseURL(),
          size: const Size(42, 42),
          radius: 6,
          cornerSmoothing: 1,
          isShowPlaceHolder: true,
          fit: BoxFit.cover);
    }

    return null;
  }
}

/// "now", "5m", "3h", "2d", then the date. Server times are UTC and some
/// arrive without a zone ("2026-10-24 21:38:42"); reading those as local time
/// put new activity hours in the past.
String _timeAgo(String? createdAt) {
  final String raw = createdAt?.trim() ?? '';
  if (raw.isEmpty) return '';
  final bool hasZone =
      raw.endsWith('Z') || RegExp(r'[+-]\d\d:?\d\d$').hasMatch(raw);
  final DateTime? parsed =
      DateTime.tryParse(hasZone ? raw : '${raw.replaceFirst(' ', 'T')}Z');
  if (parsed == null) return '';
  final DateTime time = parsed.toLocal();
  final Duration diff = DateTime.now().difference(time);
  if (diff.inMinutes < 1) return LKey.justNow.tr;
  if (diff.inHours < 1) return '${diff.inMinutes}m';
  if (diff.inDays < 1) return '${diff.inHours}h';
  if (diff.inDays < 7) return '${diff.inDays}d';
  return DateFormat('d MMM').format(time);
}

class NotificationGiftIcon extends StatelessWidget {
  final Gift? gift;

  const NotificationGiftIcon({super.key, this.gift});

  @override
  Widget build(BuildContext context) {
    return FittedBox(
      child: Container(
        height: 35,
        padding: const EdgeInsets.symmetric(horizontal: 10),
        decoration: BoxDecoration(
          color: textDarkGrey(context),
          borderRadius: BorderRadius.circular(30),
        ),
        child: Row(
          spacing: 5,
          children: [
            CustomImage(
              size: const Size(25, 25),
              image: gift?.image?.addBaseURL(),
              isShowPlaceHolder: true,
            ),
            Image.asset(AssetRes.icCoin, height: 18, width: 18),
            Text(
              (gift?.coinPrice ?? 0).numberFormat,
              // Consider using `gift?.amount.toString()` if dynamic
              style: TextStyleCustom.outFitRegular400(
                color: whitePure(context),
                fontSize: 14,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

enum ActivityNotifyType {
  none(0),
  notifyLikePost(1),
  notifyCommentPost(2),
  notifyMentionPost(3),
  notifyMentionComment(4),
  notifyFollowUser(5),
  notifyGiftUser(6),
  notifyReplyComment(7),
  notifyMentionReply(8),

  /// Someone you follow posted. Was missing, so those rows showed a bare
  /// username with nothing to tap.
  notifyNewPost(9);

  final int type;

  const ActivityNotifyType(this.type);

  /// `value` arrives as a *string* ("2") from the production host, so the old
  /// `int` parameter threw a TypeError on the implicit downcast and took the
  /// whole notification list's parse down with it. Unknown/new server-side
  /// types now degrade to [none], which the controller leaves out of the
  /// list rather than throwing.
  static ActivityNotifyType fromString(dynamic value) {
    final type = asIntOrNull(value);
    return ActivityNotifyType.values.firstWhereOrNull((e) => e.type == type) ??
        ActivityNotifyType.none;
  }
}

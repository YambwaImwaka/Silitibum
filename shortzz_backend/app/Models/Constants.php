<?php

namespace App\Models;

final class Constants
{

    const isDeletedNo = 0;
    const isDeletedYes = 1;

    const userTypeAdmin = 1;
    const userTypeUser = 0;

    const isFreeze = 1;
    const isFreezeNot = 0;

    const android = 0;
    const iOS = 1;

    const pushTypeTopic = 'topic';
    const pushTypeToken = 'token';

    const isNotifyYes = 1;

    const credit = 1;
    const debit = 0;

    const withdrawalPending = 0;
    const withdrawalCompleted = 1;
    const withdrawalRejected = 2;
    // Added for automated payouts: an async provider call (DPO/Lenco/
    // Paystack) accepted the request but hasn't confirmed completion yet
    // (Processing), or the provider call itself failed outright (Failed) —
    // neither existed when withdrawal completion was a manual admin click.
    const withdrawalProcessing = 3;
    const withdrawalFailed = 4;

    const postTypeReel = 1;
    const postTypeImage = 2;
    const postTypeVideo = 3;
    const postTypeText = 4;

    const storyTypeImage = 0;
    const storyTypeVideo = 1;

    const commentTypeText = 0;
    const commentTypeImage = 1;

    const notify_like_post = 1;
    const notify_comment_post = 2;
    const notify_mention_post = 3;
    const notify_mention_comment = 4;
    const notify_follow_user = 5;
    const notify_gift_user = 6;
    const notify_reply_comment = 7;
    const notify_mention_reply = 8;
    /// Fired to every follower when someone they follow publishes a post.
    const notify_new_post_from_following = 9;

    const userDummy = 1;
    const userReal = 0;

    // NOTE: device_token is still published here because a few client-driven
    // pushes remain (livestream invites, the post-signup welcome) and build
    // their payload from the recipient's token. Every other notification —
    // like, comment, reply, mention, follow, gift, new-post-from-following —
    // is now sent server-side, so once those last two move across, this field
    // should be dropped: exposing it lets any authenticated user send anyone
    // an arbitrary push through misc/pushNotificationToSingleUser.
    const userPublicFields= 'id,username,fullname,bio,profile_photo,is_verify,device,device_token,app_language,notify_post_like,notify_post_comment,notify_follow,notify_mention,notify_gift_received,notify_chat,notify_new_post,coin_collected_lifetime,total_post_likes_count,following_count,follower_count,receive_message';

    // Everything another person's profile screen may show: the public fields
    // plus the visibility switches it renders (frozen banner, hidden
    // following list). Anything else on tbl_users — identity, email, phone,
    // location, wallet and earnings — is the owner's alone.
    const otherUserProfileFields = Constants::userPublicFields . ',is_freez,show_my_following,who_can_view_post';

    const postsWithArray = ['images','music','user.links','user.stories','user:'.Constants::userPublicFields];

}

<?php

namespace App\Models;

use App\Jobs\TranscodeVideoJob;
use App\Mail\VerificationCodeMail;
use App\Services\Wallet\WalletLedger;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Google\Client;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PhpParser\Node\Stmt\Return_;

class GlobalFunction extends Model
{
    use HasFactory;

    public static function fetchUserFollowingIds($userId)
    {
        return Followers::where('from_user_id', $userId)->pluck('to_user_id');
    }

    public static function deleteUserAccount($user)
    {
        // Minusing followers count of other users
        $followers = Followers::where('from_user_id', $user->id)->get();
        foreach ($followers as $follower) {
            $follower->delete();
            GlobalFunction::settleFollowCount($follower->to_user_id);
        }
        $followings = Followers::where('to_user_id', $user->id)->get();
        foreach ($followings as $following) {
            $following->delete();
            GlobalFunction::settleFollowCount($following->from_user_id);
        }
        // Comments. Retained like the user's posts: deleting your account
        // should not be a way to erase what you said on someone else's
        // content. They age out on the normal retention clock.
        $comments = PostComments::where('user_id', $user->id)->get();
        foreach ($comments as $comment) {
            GlobalFunction::softDeleteComment(
                $comment,
                GlobalFunction::DELETED_BY_ACCOUNT_REMOVAL,
                $user->id
            );
        }
        // Comment Likes
        $commentLikes = CommentLikes::where('user_id', $user->id)->get();
        foreach ($commentLikes as $commentLike) {
            $commentLike->delete();
        }
        // Comment Replies
        $commentReplies = CommentReplies::where('user_id', $user->id)->get();
        foreach ($commentReplies as $commentReply) {
            $commentReply->delete();
        }
        // post likes
        $postLikes = PostLikes::where('user_id', $user->id)->get();
        foreach ($postLikes as $postLike) {
            $postLike->delete();
            GlobalFunction::settlePostLikesCount($postLike->post_id);
        }
        // Self Posts. Soft-deleted like every other removal path: "delete my
        // account" used to hard-delete the user's entire posting history,
        // which destroyed the record of anything they had been reported for
        // at the exact moment they walked away from it.
        $posts = Posts::where('user_id', $user->id)->get();
        foreach ($posts as $post) {
            GlobalFunction::softDeletePost(
                $post,
                GlobalFunction::DELETED_BY_ACCOUNT_REMOVAL,
                $user->id
            );
        }

        // Other Data
        UserNotification::where('from_user_id', $user->id)->orWhere('to_user_id', $user->id)->delete();
        // Money records are an audit trail: keep them, strip the person
        // (same as AccountDeleter). This used to hard-delete every withdrawal
        // request — open ones vanished from the admin queue unpaid, and
        // completed ones took the payout history with them.
        RedeemRequests::where('user_id', $user->id)->update(['user_id' => null]);
        CoinTopUpRequests::where('user_id', $user->id)->update(['user_id' => null]);
        ReportUsers::where('user_id', $user->id)->orWhere('by_user_id', $user->id)->delete();
        ReportPosts::where('by_user_id', $user->id)->delete();

        Story::where('user_id', $user->id)->delete();
        UserLinks::where('user_id', $user->id)->delete();
    }

    /// Hydrates the payload a single activity-feed row renders from.
    ///
    /// Every lookup here is null-guarded on purpose. Notification rows outlive
    /// the things they point at — an admin deleting a comment purges nothing,
    /// and the user-facing delete only purges rows whose from_user_id matches
    /// the deleter — so `find()` routinely returns null for stale rows. This
    /// used to assign straight onto that null (`$comment->mentionedUsers = …`),
    /// which is a fatal on PHP 8, so ONE stale row 500'd the entire Activity
    /// response and the app was left spinning forever. Missing data now
    /// degrades to null and the caller drops the row.
    public static function getNotificationItemData($notifyItem, $user)
    {
        $data = null;
        switch ($notifyItem->type) {
            case Constants::notify_like_post:
            case Constants::notify_mention_post:
            case Constants::notify_new_post_from_following:
                $post = GlobalFunction::preparePostFullData($notifyItem->data_id);
                if ($post == null) {
                    return null;
                }
                $data['post'] = $post;
                break;
            case Constants::notify_comment_post:
            case Constants::notify_mention_comment:
                $comment = PostComments::find($notifyItem->data_id);
                if ($comment == null) {
                    return null;
                }
                $comment->mentionedUsers = Users::whereIn('id', explode(',', $comment->mentioned_user_ids))
                    ->select(explode(',', Constants::userPublicFields))
                    ->get();
                $post = GlobalFunction::preparePostFullData($comment->post_id);
                if ($post == null) {
                    return null;
                }
                $data['comment'] = $comment;
                $data['post'] = $post;
                break;
            case Constants::notify_gift_user:
                $gift = Gifts::find($notifyItem->data_id);
                if ($gift == null) {
                    return null;
                }
                $data['gift'] = $gift;
                break;
            case Constants::notify_reply_comment:
            case Constants::notify_mention_reply:
                $reply = CommentReplies::find($notifyItem->data_id);
                if ($reply == null) {
                    return null;
                }
                $comment = PostComments::find($reply->comment_id);
                if ($comment == null) {
                    return null;
                }
                $post = GlobalFunction::preparePostFullData($comment->post_id);
                if ($post == null) {
                    return null;
                }
                $data['reply'] = $reply;
                $data['comment'] = $comment;
                $data['post'] = $post;
                break;
        }

        if (Arr::has($data, 'post') && $data['post'] != null) {
            $post = $data['post'];

            $post->is_liked = PostLikes::where('post_id', $post->id)->where('user_id', $user->id)->exists();
            $post->is_saved = PostSaves::where('post_id', $post->id)->where('user_id', $user->id)->exists();
            // The author row can be gone (deleted account) while the post is
            // still hydrated — same fatal-on-null hazard as above.
            if ($post->user != null) {
                $post->user->is_following = Followers::where('from_user_id', $user->id)->where('to_user_id', $post->user_id)->exists();
            }
            $post->mentionedUsers = Users::whereIn('id', explode(',', $post->mentioned_user_ids))
                ->select(explode(',', Constants::userPublicFields))
                ->get();

            $data['post'] = $post;
        }

        return $data;
    }

    public static function deleteNotifications($notifyType, $dataId, $fromUserId = null)
    {
        switch ($notifyType) {
            case Constants::notify_like_post:
                UserNotification::where([
                    'type' => $notifyType,
                    'from_user_id' => $fromUserId,
                    'data_id' => $dataId,
                ])->delete();
                break;
            case Constants::notify_comment_post:
                UserNotification::whereIn('type', [Constants::notify_comment_post, Constants::notify_mention_comment])
                    ->where('data_id', $dataId)
                    ->where('from_user_id', $fromUserId)
                    ->delete();
                break;
            case Constants::notify_reply_comment:
                UserNotification::whereIn('type', [Constants::notify_reply_comment, Constants::notify_mention_reply])
                    ->where('data_id', $dataId)
                    ->where('from_user_id', $fromUserId)
                    ->delete();
                break;
            case Constants::notify_follow_user:
                UserNotification::whereIn('type', [Constants::notify_follow_user])
                    ->where('data_id', $dataId)
                    ->where('from_user_id', $fromUserId)
                    ->delete();
                break;
        }
    }

    /// Where the minted FCM access token lives. Cache, not tbl_settings —
    /// see regenerateGoogleAPIToken() for why.
    const FCM_TOKEN_CACHE_KEY = 'fcm_access_token';

    /**
     * Mints a short-lived FCM access token from googleCredentials.json.
     *
     * This used to write the token into tbl_settings.place_api_access_token,
     * a column SettingsController::fetchSettings serializes to every caller
     * holding the app's hardcoded (therefore public) apikey — so the token
     * was handed to anyone who asked. It also requested cloud-platform
     * scope, which covers essentially every GCP API the service account can
     * reach, not just push.
     *
     * Both are fixed here: the token is cached server-side where nothing
     * serializes it, and the scope is narrowed to firebase.messaging, which
     * is all the server ever does with it. Google issues these with a
     * one-hour life; the 50-minute TTL leaves margin so a cached token
     * cannot expire mid-send.
     *
     * Returns the token so callers can use it without a second cache read.
     */
    public static function regenerateGoogleAPIToken()
    {
        $client = new Client();
        // base_path(), not a bare relative name: under PHP-FPM/LiteSpeed the
        // working directory is the docroot (public/), so the relative form
        // never resolved and token regeneration failed even on hosts where
        // sendPushNotification() could read the same file fine.
        $client->setAuthConfig(base_path('googleCredentials.json'));
        $client->addScope('https://www.googleapis.com/auth/firebase.messaging');
        $client->fetchAccessTokenWithAssertion();
        $accessToken = $client->getAccessToken();
        $accessToken = $accessToken['access_token'] ?? null;

        if (empty($accessToken)) {
            throw new \RuntimeException('regenerateGoogleAPIToken: no access_token in the assertion response');
        }

        Cache::put(self::FCM_TOKEN_CACHE_KEY, $accessToken, now()->addMinutes(50));

        return $accessToken;
    }

    public static function insertUserNotification($type, $fromUserId, $toUserId, $dataId)
    {
        // Store Notification
        if ($fromUserId != $toUserId) {
            $item = new UserNotification();
            $item->type = $type;
            $item->from_user_id = $fromUserId;
            $item->to_user_id = $toUserId;
            $item->data_id = $dataId;
            $item->save();
            return $item;
        }
        return null;
    }

    public static function initiatePushNotification($notifyON, $notSelfPost, $toUser, $title, $description, $notificationData)
    {
        if ($notifyON && $notSelfPost) {
            $notifyPayload = GlobalFunction::generatePushNotificationPayload($toUser->device, Constants::pushTypeToken, $toUser->device_token, $title, $description, null, $notificationData);
            GlobalFunction::sendPushNotification($notifyPayload);
        }
    }

    /**
     * Sends a push for a notification the server has already recorded.
     *
     * Server-originated on purpose. Every push except chat used to be built
     * and fired by the SENDER's device through
     * misc/pushNotificationToSingleUser, which meant a notification only
     * arrived if the sender's app was still alive, and it happened to hold the
     * recipient's FCM token — which in turn required device_token to be
     * published in every user payload. Doing it here removes both the
     * reliability problem and the reason to leak the token.
     *
     * Never throws: a failed push must not fail the like/comment/follow that
     * triggered it.
     */
    public static function pushToUser($toUserId, $title, $body, array $data = [], $preferenceColumn = null)
    {
        try {
            $toUser = Users::find($toUserId);
            if ($toUser == null || empty($toUser->device_token) || $toUser->is_freez == 1) {
                return false;
            }
            // Per-type opt-out (notify_post_like, notify_follow, ...).
            if ($preferenceColumn !== null && isset($toUser->$preferenceColumn) && $toUser->$preferenceColumn != 1) {
                return false;
            }

            $payload = GlobalFunction::generatePushNotificationPayload(
                $toUser->device,
                Constants::pushTypeToken,
                $toUser->device_token,
                $title,
                $body,
                null,
                $data
            );

            return GlobalFunction::sendPushNotification($payload);
        } catch (\Throwable $e) {
            Log::error('pushToUser failed for user ' . $toUserId . ': ' . $e->getMessage());
            return false;
        }
    }

    public static function generatePushNotificationPayload($deviceType, $topicOrToken, $pushToken, $title, $description, $image = null, $data = null)
    {
        $notificationPayload = [
            'title' => $title,
            'body' => $description,
        ];

        if ($image) {
            $notificationPayload['image'] = $image;
        }

        $messagePayload = [
            $topicOrToken => $pushToken,
        ];

        if ($data) {
            $data = array_map(fn($value) => (string) $value, $data);
        }

        switch ($deviceType) {
            case Constants::android:
                $messagePayload['data'] = $data ? array_merge($notificationPayload, $data) : $notificationPayload;
                $messagePayload['android'] = ['priority' => 'high'];
                break;

            case Constants::iOS:
                $messagePayload['notification'] = $notificationPayload;
                if ($data) {
                    $messagePayload['data'] = $data;
                }
                $messagePayload['apns'] = [
                    'payload' => ['aps' => ['sound' => 'default']],
                ];
                break;
        }

        return ['message' => $messagePayload];
    }

    /// Per-process cache of the FCM project id. sendPushNotification() used to
    /// re-read googleCredentials.json from disk on EVERY call; a follower
    /// fan-out of 300 recipients meant 300 file reads for one unchanging value.
    private static $fcmProjectId = null;

    public static function sendPushNotification($payload)
    {
        $projectId = self::fcmProjectId();
        if ($projectId === null) {
            // Missing/unreadable service-account file. Log once per call site
            // rather than throwing: a broken push must never take down the
            // request that triggered it (posting, commenting, following).
            Log::error('sendPushNotification: googleCredentials.json missing or unreadable at ' . base_path('googleCredentials.json'));
            return false;
        }

        $url = 'https://fcm.googleapis.com/v1/projects/' . $projectId . '/messages:send';

        $response = self::postToFcm($url, $payload);
        if ($response === null) {
            return false;
        }
        $responseBody = json_decode($response->body(), true);

        if (isset($responseBody['error']['code']) && $responseBody['error']['code'] == 401) {
            // Access tokens expire hourly. The old code regenerated and then
            // returned, so the FIRST push after every expiry was silently
            // dropped — retry once with the fresh token instead.
            try {
                GlobalFunction::regenerateGoogleAPIToken();
            } catch (\Throwable $e) {
                Log::error('regenerateGoogleAPIToken failed: ' . $e->getMessage());
                return false;
            }

            $response = self::postToFcm($url, $payload);
            if ($response === null) {
                return false;
            }
            $responseBody = json_decode($response->body(), true);
        }

        if (isset($responseBody['error'])) {
            // UNREGISTERED / NotRegistered means the app instance that owned
            // this token is gone (uninstalled, or reinstalled and issued a new
            // token). The token can never succeed again, so drop it instead of
            // re-sending to it on every like, comment and follow forever.
            // 7 of the 15 device tokens on production were in this state when
            // push was first brought up.
            $errorCode = $responseBody['error']['details'][0]['errorCode']
                ?? ($responseBody['error']['status'] ?? '');
            if ($errorCode === 'UNREGISTERED' || $errorCode === 'NOT_FOUND') {
                self::forgetDeviceToken($payload['message']['token'] ?? null);
            }

            Log::warning('FCM send failed: ' . ($responseBody['error']['message'] ?? 'unknown error'));
            return false;
        }

        return $response->successful();
    }

    /// Clears a device token FCM has told us is dead. Never throws: this runs
    /// inside a push attempt, which is itself best-effort.
    private static function forgetDeviceToken($deviceToken)
    {
        if (empty($deviceToken)) {
            return;
        }
        try {
            Users::where('device_token', $deviceToken)->update(['device_token' => null]);
        } catch (\Throwable $e) {
            Log::warning('forgetDeviceToken failed: ' . $e->getMessage());
        }
    }

    /// Returns the FCM response, or null when no access token could be
    /// obtained at all (caller treats that as a failed send).
    private static function postToFcm($url, $payload)
    {
        // Read fresh each attempt: regenerateGoogleAPIToken() writes the new
        // token to the cache, and the 401 retry above depends on picking it up.
        $accessToken = Cache::get(self::FCM_TOKEN_CACHE_KEY);

        // Cold cache (first push after a deploy, a cache clear, or the 50-minute
        // TTL lapsing with no traffic). Mint one now rather than burning this
        // send on a guaranteed 401.
        if (empty($accessToken)) {
            try {
                $accessToken = self::regenerateGoogleAPIToken();
            } catch (\Throwable $e) {
                Log::error('postToFcm: could not mint an FCM access token: ' . $e->getMessage());
                return null;
            }
        }

        return Http::withToken($accessToken)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->timeout(10)
            ->post($url, $payload);
    }

    private static function fcmProjectId()
    {
        if (self::$fcmProjectId !== null) {
            return self::$fcmProjectId;
        }

        $path = base_path('googleCredentials.json');
        if (!File::exists($path)) {
            return null;
        }

        $credentials = json_decode(File::get($path), true);
        if (!is_array($credentials) || empty($credentials['project_id'])) {
            return null;
        }

        return self::$fcmProjectId = $credentials['project_id'];
    }

    public static function determineUserLevel($userId)
    {
        $user = Users::find($userId);

        // Get the highest level the user qualifies for
        $level = UserLevels::where('coins_collection', '<=', $user->coin_collected_lifetime)->orderBy('coins_collection', 'desc')->first();

        return $level ? $level->level : 1; // Default to level 1 if no match
    }

    public static function createUserModeratorSwitch($user, $type = 'all')
    {
        $checked = $user->is_moderator == Constants::isFreeze ? 'checked' : '';

        $moderatorSwitch = "<input type='checkbox' id='moderrator-status-{$user->id}{$type}' rel='{$user->id}' class='moderatorUser' {$checked} data-switch='none'/>
                    <label for='moderrator-status-{$user->id}{$type}'></label>";

        return $moderatorSwitch;
    }
    public static function createUserFreezeSwitch($user, $type = 'all')
    {
        $checked = $user->is_freez == Constants::isFreeze ? 'checked' : '';

        $freezeSwitch = "<input type='checkbox' id='block-all-user-{$user->id}{$type}' rel='{$user->id}' class='freezeUser' {$checked} data-switch='none'/>
                    <label for='block-all-user-{$user->id}{$type}'></label>";

        return $freezeSwitch;
    }
    public static function createPostDetailsButton($postId)
    {
        $view =
            "<a href=\"" .
            route('postDetails', $postId) .
            "\"
                      rel=\"{$postId}\"
                      class=\"action-btn delete d-flex align-items-center justify-content-center btn border rounded-2 text-info ms-1\">
                        <i class=\"ri-eye-line\"></i>
                    </a>";

        return $view;
    }
    public static function createPostDeleteButton($postId)
    {
        $delete = "<a href='#'
                          rel='{$postId}'
                          class='action-btn delete-post d-flex align-items-center justify-content-center btn border rounded-2 text-danger ms-1'>
                            <i class='uil-trash-alt'></i>
                        </a>";

        return $delete;
    }
    public static function createCommentStatesView($commentId)
    {
        $item = PostComments::find($commentId);

        $likes = "<span class='rounded-pill bg-light px-2 py-1 me-1 border'><i class='uil-heart fs-4'></i> : {$item->likes}</span>";
        $replies = "<span class='rounded-pill bg-light px-2 py-1  me-1 border'><i class='uil-comments fs-4'></i> : {$item->replies_count}</span>";

        $states = '<div class="">' . $likes . $replies . '</div>';

        return $states;
    }
    public static function createPostStatesView($postId)
    {
        $post = Posts::find($postId);
        $contentType = $post->post_type;

        $formattedLikes = GlobalFunction::formatNumber($post->likes);
        $formattedComments = GlobalFunction::formatNumber($post->comments);
        $formattedSaves = GlobalFunction::formatNumber($post->saves);
        $formattedShares = GlobalFunction::formatNumber($post->shares);
        $formattedViews = GlobalFunction::formatNumber($post->views);

        $likes = "<span class='d-inline-flex align-items-center rounded-pill bg-light px-2 py-1 me-1 my-1 border'><i class='uil-heart fs-4'></i> : {$formattedLikes}</span>";
        $comments = "<span class='d-inline-flex align-items-center rounded-pill bg-light px-2 py-1  me-1 my-1 border'><i class='uil-comment fs-4'></i> : {$formattedComments}</span>";
        $saves = "<span class='d-inline-flex align-items-center rounded-pill bg-light px-2 py-1  me-1 my-1 border'><i class='uil-bookmark fs-4'></i> : {$formattedSaves}</span>";
        $shares = "<span class='d-inline-flex align-items-center rounded-pill bg-light px-2 py-1  me-1 my-1 border'><i class='uil-share-alt fs-4'></i> : {$formattedShares}</span>";
        $postViews = '';
        if ($contentType == Constants::postTypeReel || $contentType == Constants::postTypeVideo) {
            $postViews = "<span class='d-inline-flex align-items-center rounded-pill bg-light px-2 py-1  me-1 my-1 border'><i class='uil-eye fs-4'></i> : {$formattedViews} </span>";
        }
        $states = '<div class="">' . $likes . $comments . $saves . $shares . $postViews . '</div>';

        return $states;
    }

    public static function createUserDetailsColumn($userId)
    {
        $user = Users::find($userId);
        if ($user == null) {
            return '';
        }

        // e() on everything user-controlled: this card is rendered as HTML in
        // nearly every admin table (users, posts, comments, reports,
        // withdrawals), and username/fullname are free text a user sets in
        // the app — unescaped, any account could run script in the admin's
        // session just by being listed.
        $profileImageUrl = e($user->profile_photo != null ? GlobalFunction::generateFileUrl($user->profile_photo) : url('assets/img/placeholder.png'));

        $verifyIcon = '';
        if ($user->is_verify == 1) {
            $verifyIcon = '<img src=' . asset('assets/img/ic_verify.png') . ' alt="profile" class="rounded-circle object-fit-cover custom-width-18px custom-height-18px">';
        }
        $postUser =
            '
                     <div class="card rounded-pill w-auto d-inline-block pe-3 shadow-none bg-light m-0 border">
                        <a target="_blank" href="' .
            route('viewUserDetails', $user->id) .
            '" class="table-user d-flex p-1">
                        <img id="imgUserProfile-userDetails" src="' .
            $profileImageUrl .
            '" class="me-2 avatar-sm rounded-circle object-fit-cover bg-primary-lighten text-info rounded border">
                        <div class="d-grid text-start">
                            <div class="text-body text-dark fw-semibold">' .
            e($user->username) .
            $verifyIcon .
            '</div>
                            <div class="text-secondary fs-6">' .
            e($user->fullname) .
            '</div>
                        </div>
                        </a>
                    </div>';
        return $postUser;
    }

    public static function formatNumber($number, $precision = 1)
    {
        if ($number >= 1_000_000_000) {
            return number_format($number / 1_000_000_000, $precision) . 'B';
        } elseif ($number >= 1_000_000) {
            return number_format($number / 1_000_000, $precision) . 'M';
        } elseif ($number >= 1_000) {
            return number_format($number / 1_000, $precision) . 'K';
        } else {
            return $number;
        }
    }

    public static function createStoryTypeBadge($storyId)
    {
        $story = Story::find($storyId);

        switch ($story->type) {
            case Constants::storyTypeImage:
                $type = 'Image';
                break;
            case Constants::storyTypeVideo:
                $type = 'Video';
                break;
        }
        $storyType = "<span class='badge badge-info-lighten fs-5'>{$type}</span>";

        return $storyType;
    }
    public static function createUserTypeBadge($userId)
    {
        $user = Users::find($userId);

        switch ($user->is_dummy) {
            case Constants::userDummy:
                $userType = 'Dummy';
                break;
            case Constants::userReal:
                $userType = 'Real';
                break;
        }
        $userType = "<span class='badge badge-info-lighten fs-5'>{$userType}</span>";

        return $userType;
    }
    public static function createPostTypeBadge($postId)
    {
        $post = Posts::find($postId);

        switch ($post->post_type) {
            case Constants::postTypeImage:
                $postType = 'Image';
                break;
            case Constants::postTypeVideo:
                $postType = 'Video';
                break;
            case Constants::postTypeText:
                $postType = 'Text';
                break;
            case Constants::postTypeReel:
                $postType = 'Snap';
                break;
        }
        $postType = "<span class='badge badge-info-lighten fs-5'>{$postType}</span>";

        return $postType;
    }

    public static function createViewStoryButton($story)
    {
        $storyType = $story->type;

        $viewStory = '';
        $story->content = GlobalFunction::getItemBaseUrl() . $story->content;

        // Every attribute quoted and e()-escaped. username is free text the
        // user sets and these were unquoted, so a space or quote in it could
        // inject new attributes — event handlers — into the admin page.
        $attributes = ' data-contenttype="' . e($storyType) . '"'
            . ' data-username="' . e($story->user->username ?? '') . '"'
            . ' data-content="' . e($story->content) . '"'
            . ' data-userid="' . e($story->user->id ?? '') . '"'
            . ' data-storyid="' . e($story->id) . '"'
            . ' rel="' . e($story->id) . '"';

        if ($storyType == Constants::storyTypeImage) {
            $viewStory = '<button type="button"' . $attributes . ' class="btn btn-primary viewImageStory rounded-pill d-flex align-items-center commonViewBtn" data-bs-toggle="modal" data-bs-target="#imageStoryModal">
            <i class="uil-image-v fs-4 me-1" style="line-height: 18px;"></i> View Content</button>';
        } elseif ($storyType == Constants::storyTypeVideo) {
            $viewStory = '<button type="button"' . $attributes . ' class="btn btn-primary viewVideoStory rounded-pill d-flex align-items-center commonViewBtn" data-bs-toggle="modal" data-bs-target="#videoStoryModal">
            <i class="uil-video fs-3 me-1" style="line-height: 18px;"></i> View Content</button>';
        }

        return $viewStory;
    }
    public static function createViewContentButton($post)
    {
        $contentType = $post->post_type;

        // Every attribute quoted and e()-escaped. The caption and username are
        // free text the user controls, and were concatenated in unescaped
        // (some attributes unquoted), so a post could inject markup and event
        // handlers into every admin table it appeared in.
        $attributes = ' data-contenttype="' . e($contentType) . '"'
            . ' data-username="' . e($post->user->username ?? '') . '"'
            . ' data-userid="' . e($post->user->id ?? '') . '"'
            . ' data-desc="' . e($post->description) . '"'
            . ' data-postid="' . e($post->id) . '"'
            . ' rel="' . e($post->id) . '"';

        $viewContent = '';
        if ($contentType == Constants::postTypeImage) {
            $images = $post->images;
            foreach ($images as $image) {
                $image->image = GlobalFunction::getItemBaseUrl() . $image->image;
            }

            // JSON, escaped into a quoted attribute; jQuery's .data('images')
            // decodes and parses it exactly as before.
            $viewContent = '<button type="button"' . $attributes . ' data-images="' . e((string) $images) . '" class="btn btn-primary viewImagePost rounded-pill d-flex align-items-center commonViewBtn" data-bs-toggle="modal" data-bs-target="#imagePostModal">
            <i class="uil-image-v fs-4 me-1" style="line-height: 18px;"></i> View Content</button>';
        } elseif ($contentType == Constants::postTypeVideo) {
            $viewContent = '<button type="button"' . $attributes . ' data-videourl="' . e(GlobalFunction::generateFileUrl($post->video)) . '" class="btn btn-primary viewVideoPost rounded-pill d-flex align-items-center commonViewBtn" data-bs-toggle="modal" data-bs-target="#videoPostModal">
            <i class="uil-video fs-3 me-1" style="line-height: 18px;"></i> View Content</button>';
        } elseif ($contentType == Constants::postTypeText) {
            $viewContent = '<button type="button"' . $attributes . ' class="btn btn-primary viewTextPost rounded-pill d-flex align-items-center commonViewBtn" data-bs-toggle="modal" data-bs-target="#textPostModal">
            <i class="uil-text fs-3 me-1" style="line-height: 18px;"></i> View Content</button>';
        } elseif ($contentType == Constants::postTypeReel) {
            $viewContent = '<button type="button"' . $attributes . ' data-videourl="' . e(GlobalFunction::generateFileUrl($post->video)) . '" class="btn btn-primary viewReelPost rounded-pill d-flex align-items-center commonViewBtn" data-bs-toggle="modal" data-bs-target="#videoPostModal">
            <i class="uil-play fs-4 me-1" style="line-height: 18px;"></i> View Content</button>';
        }

        return $viewContent;
    }

    /// Also the root of every payout reference a provider sees
    /// (`{request_number}-{attempt}`), and Lenco only accepts [A-Za-z0-9._-]
    /// and rejects any reference it has seen before. This used to be
    /// `userId-rand(1000..9999)` with its uniqueness checked against
    /// tbl_users.username — the wrong table entirely.
    public static function generateRedeemRequestNumber($userId)
    {
        do {
            $number = 'WD' . (int) $userId . '-' . strtoupper(Str::random(8));
        } while (RedeemRequests::where('request_number', $number)->exists());
        return $number;
    }

    /// Badge for the admin "Deleted content" list, so who removed a post is
    /// readable at a glance rather than a bare integer.
    public static function deletionSourceLabel($source)
    {
        [$text, $class] = match ((int) $source) {
            self::DELETED_BY_AUTHOR => ['Author', 'bg-secondary'],
            self::DELETED_BY_ADMIN => ['Admin', 'bg-primary'],
            self::DELETED_BY_MODERATOR => ['Moderator', 'bg-info'],
            self::DELETED_BY_REPORT => ['Report accepted', 'bg-danger'],
            self::DELETED_BY_ACCOUNT_REMOVAL => ['Account deleted', 'bg-dark'],
            default => ['Unknown', 'bg-secondary'],
        };

        return "<span class='badge {$class} mb-1'>{$text}</span>";
    }

    // Deletion sources for tbl_post.deletion_source.
    const DELETED_BY_AUTHOR = 0;
    const DELETED_BY_ADMIN = 1;
    const DELETED_BY_MODERATOR = 2;
    const DELETED_BY_REPORT = 3;
    const DELETED_BY_ACCOUNT_REMOVAL = 4;

    /**
     * Soft-deletes a post, stamping who removed it, why, and when it becomes
     * eligible for permanent purge.
     *
     * Replaces the old `$post->delete(); deleteAllPostData($post);` pairing at
     * every delete site. That combination destroyed the likes, comments and —
     * critically — the report_posts row recording WHY the content was taken
     * down, at exactly the moment that record matters most. Everything of
     * evidentiary value is now kept until purge_after passes; only the
     * notification rows pointing at the post are cleared, because a
     * notification that opens a post the user can no longer see is a dead tap
     * (and those self-prune after 7 days anyway).
     */
    public static function softDeletePost($post, int $source, $deletedBy = null, ?string $reason = null)
    {
        if ($post == null) {
            return;
        }

        $settings = GlobalSettings::first();
        // Report-driven removals get the longer clock: that is the case with
        // real legal exposure, so the evidence has to outlive a routine
        // self-delete.
        $days = $source === self::DELETED_BY_REPORT
            ? (int) ($settings->report_retention_days ?? 365)
            : (int) ($settings->post_retention_days ?? 90);

        $post->deleted_by = $deletedBy;
        $post->deletion_source = $source;
        $post->deletion_reason = $reason;
        $post->purge_after = Carbon::now()->addDays($days);
        $post->save();

        $post->delete(); // soft

        self::detachPostFromFeeds($post);
    }

    /**
     * Soft-deletes a comment (and its replies), stamping attribution and the
     * purge deadline — the comment equivalent of softDeletePost().
     *
     * Replies go with the parent: a reply thread read without the comment it
     * answers is not a usable record of what was said.
     */
    public static function softDeleteComment($comment, int $source, $deletedBy = null)
    {
        if ($comment == null) {
            return;
        }

        $settings = GlobalSettings::first();
        $purgeAfter = Carbon::now()->addDays((int) ($settings->post_retention_days ?? 90));

        CommentReplies::where('comment_id', $comment->id)
            ->update([
                'deleted_at' => Carbon::now(),
                'deleted_by' => $deletedBy,
                'deletion_source' => $source,
                'purge_after' => $purgeAfter,
            ]);

        $comment->deleted_by = $deletedBy;
        $comment->deletion_source = $source;
        $comment->purge_after = $purgeAfter;
        $comment->save();
        $comment->delete(); // soft

        GlobalFunction::settlePostCommentCount($comment->post_id);

        // The notification pointing at it must go — tapping through to a
        // comment the user can no longer see is a dead end. Not filtered by
        // from_user_id: an admin or moderator removing someone else's comment
        // has to clear it too, which the old call site did not do.
        UserNotification::whereIn('type', [
            Constants::notify_comment_post,
            Constants::notify_mention_comment,
        ])->where('data_id', $comment->id)->delete();
    }

    /**
     * Soft-deletes a single reply.
     */
    public static function softDeleteCommentReply($reply, int $source, $deletedBy = null)
    {
        if ($reply == null) {
            return;
        }

        $settings = GlobalSettings::first();

        $reply->deleted_by = $deletedBy;
        $reply->deletion_source = $source;
        $reply->purge_after = Carbon::now()->addDays((int) ($settings->post_retention_days ?? 90));
        $reply->save();
        $reply->delete(); // soft

        GlobalFunction::settleCommentsRepliesCount($reply->comment_id);

        UserNotification::whereIn('type', [
            Constants::notify_reply_comment,
            Constants::notify_mention_reply,
        ])->where('data_id', $reply->id)->delete();
    }

    /**
     * Clears the derived/UI state that must not outlive a removed post, while
     * leaving the post row, its media and all engagement history intact.
     */
    public static function detachPostFromFeeds($post)
    {
        UserNotification::whereIn('type', [
            Constants::notify_like_post,
            Constants::notify_mention_post,
            Constants::notify_new_post_from_following,
        ])->where('data_id', $post->id)->delete();

        $commentIds = PostComments::where('post_id', $post->id)->pluck('id');
        $replyIds = CommentReplies::whereIn('comment_id', $commentIds)->pluck('id');

        UserNotification::whereIn('type', [Constants::notify_reply_comment, Constants::notify_mention_reply])
            ->whereIn('data_id', $replyIds)
            ->delete();
        UserNotification::whereIn('type', [Constants::notify_comment_post, Constants::notify_mention_comment])
            ->whereIn('data_id', $commentIds)
            ->delete();

        // The counters below are recount queries against Posts::, so with the
        // SoftDeletes scope in place they self-correct — a removed post stops
        // being counted with no extra bookkeeping here.
        if ($post->hashtags != null) {
            foreach (explode(',', $post->hashtags) as $tag) {
                $hashtag = Hashtags::where('hashtag', $tag)->first();
                if ($hashtag != null) {
                    GlobalFunction::settleHashtagPostCounts($hashtag->id);
                }
            }
        }
        if ($post->sound_id != null) {
            $sound = Musics::find($post->sound_id);
            if ($sound != null) {
                GlobalFunction::settleSoundPostCounts($sound->id);
            }
        }
        if ($post->user_id != null) {
            GlobalFunction::settleUserTotalPostLikesCount($post->user_id);
        }
    }

    /**
     * Permanent removal. Only the retention sweep (posts:purge-retention) and
     * an explicit admin purge should call this — it is the point of no return.
     */
    public static function deleteAllPostData($post)
    {
        PostLikes::where('post_id', $post->id)->delete();
        PostSaves::where('post_id', $post->id)->delete();
        ReportPosts::where('post_id', $post->id)->delete();

        // ***** Start : Delete notifications attached with that post

        UserNotification::whereIn('type', [Constants::notify_like_post, Constants::notify_mention_post])
            ->where('data_id', $post->id)
            ->delete();

        $commentIds = PostComments::where('post_id', $post->id)->pluck('id');
        $replyIds = CommentReplies::whereIn('comment_id', $commentIds)->pluck('id');

        //  Replies Notification Delete
        UserNotification::whereIn('type', [Constants::notify_reply_comment, Constants::notify_mention_reply])
            ->whereIn('data_id', $replyIds)
            ->delete();
        //  Comment Notification Delete
        UserNotification::whereIn('type', [Constants::notify_comment_post, Constants::notify_mention_comment])
            ->whereIn('data_id', $commentIds)
            ->delete();

        // ***** End : Delete notifications attached with that post

        // withTrashed()/forceDelete(): comments and replies are soft-deletable
        // now, so a plain get()+delete() here would skip already-removed rows
        // and only soft-delete the rest — leaving orphans behind a purge whose
        // whole purpose is permanent removal.
        $comments = PostComments::withTrashed()->where('post_id', $post->id)->get();
        foreach ($comments as $comment) {
            CommentReplies::withTrashed()->where('comment_id', $comment->id)->forceDelete();
            CommentLikes::where('comment_id', $comment->id)->delete();
            $comment->forceDelete();
        }

        // Deleting Images
        if ($post->images) {
            foreach ($post->images as $image) {
                GlobalFunction::deleteFile($image->image);
                $image->delete();
            }
        }

        // Video posts leaked their media on every delete: only the images
        // above were ever cleaned up, so each removed reel left its source
        // MP4, poster frame, HLS ladder and ffmpeg scratch directory on disk
        // forever.
        if (!empty($post->video)) {
            GlobalFunction::deleteFile($post->video);
        }
        if (!empty($post->thumbnail)) {
            GlobalFunction::deleteFile($post->thumbnail);
        }
        if (!empty($post->hls_path)) {
            GlobalFunction::deleteHlsDirectory($post->hls_path);
        }
        $scratchDir = storage_path('app/transcode/' . $post->id);
        if (is_dir($scratchDir)) {
            File::deleteDirectory($scratchDir);
        }

        // Minusing video count on hashtag
        if ($post->hashtags != null) {
            $hash_tag_array = explode(',', $post->hashtags);
            foreach ($hash_tag_array as $tag) {
                $hashtag = Hashtags::where('hashtag', $tag)->first();
                if ($hashtag != null) {
                    GlobalFunction::settleHashtagPostCounts($hashtag->id);
                }
            }
        }
        // Settle post count on sound
        if ($post->sound_id != null) {
            $sound = Musics::find($post->sound_id);
            if ($sound != null) {
                GlobalFunction::settleSoundPostCounts($sound->id);
            }
        }

        // A live recording is published as an ordinary post, so purging it has
        // to clear the livestream's pointer too — otherwise the stream keeps
        // advertising a recording_post_id the app cannot open, and a re-upload
        // would be refused as "already stored".
        Livestream::where('recording_post_id', $post->id)->update([
            'recording' => null,
            'recording_post_id' => null,
            'recording_status' => 0,
        ]);

        $user = Users::find($post->user_id);
        GlobalFunction::settleUserTotalPostLikesCount($user->id);
    }

    // Batched: the old version ran ~6 queries PER POST (likes/saves/follow
    // exists() checks, mentioned users, author re-fetch) — a 20-post page cost
    // 100+ queries. This version answers the whole page in at most 5 queries
    // while returning byte-identical data to the app.
    public static function processPostsListData($posts, $user)
    {
        if (count($posts) == 0) {
            return [];
        }

        $postIds = collect($posts)->pluck('id')->all();
        $authorIds = collect($posts)->pluck('user_id')->unique()->all();

        // Viewer context in 3 queries (guest sentinel id=0 skips them: every
        // flag is false for guests, same as the old per-post lookups).
        $likedPostIds = [];
        $savedPostIds = [];
        $followingAuthorIds = [];
        if ($user->id) {
            $likedPostIds = array_flip(PostLikes::where('user_id', $user->id)->whereIn('post_id', $postIds)->pluck('post_id')->all());
            $savedPostIds = array_flip(PostSaves::where('user_id', $user->id)->whereIn('post_id', $postIds)->pluck('post_id')->all());
            $followingAuthorIds = array_flip(Followers::where('from_user_id', $user->id)->whereIn('to_user_id', $authorIds)->pluck('to_user_id')->all());
        }

        // All mentioned users across the page in one query.
        $mentionedIdsByPost = [];
        $allMentionedIds = [];
        foreach ($posts as $post) {
            $ids = $post->mentioned_user_ids ? array_filter(explode(',', $post->mentioned_user_ids)) : [];
            $mentionedIdsByPost[$post->id] = $ids;
            foreach ($ids as $id) {
                $allMentionedIds[] = $id;
            }
        }
        $mentionedUsersById = collect();
        if (count($allMentionedIds) > 0) {
            $mentionedUsersById = Users::whereIn('id', array_unique($allMentionedIds))
                ->select(explode(',', Constants::userPublicFields))
                ->get()
                ->keyBy('id');
        }

        // The eager-loaded post->user only carries public fields, so fetch
        // who_can_view_post for all authors in one query.
        $whoCanViewByAuthor = Users::whereIn('id', $authorIds)->pluck('who_can_view_post', 'id');

        $new_post_list = [];
        foreach ($posts as $post) {
            $post->is_liked = isset($likedPostIds[$post->id]);
            $post->is_saved = isset($savedPostIds[$post->id]);
            if ($post->user != null) {
                $post->user->is_following = isset($followingAuthorIds[$post->user_id]);
            }

            $mentionedUsers = [];
            foreach ($mentionedIdsByPost[$post->id] as $id) {
                $mUser = $mentionedUsersById->get((int) $id);
                if ($mUser != null) {
                    $mentionedUsers[] = $mUser;
                }
            }
            $post->mentioned_users = collect($mentionedUsers);

            // Filter of who can view post
            if (($whoCanViewByAuthor[$post->user_id] ?? 0) == 1) {
                if (isset($followingAuthorIds[$post->user_id]) || $post->user_id == $user->id) {
                    array_push($new_post_list, $post);
                }
            } else {
                array_push($new_post_list, $post);
            }
        }

        return $new_post_list;
    }

    // Same batching idea as processPostsListData, applied to comments/replies:
    // resolves every item's @mentioned users in 1 query total instead of 1
    // query per item. Mutates each item in place (sets ->mentionedUsers).
    public static function attachMentionedUsers($items)
    {
        if (count($items) == 0) {
            return;
        }

        $idsByItem = [];
        $allMentionedIds = [];
        foreach ($items as $item) {
            $ids = $item->mentioned_user_ids ? array_filter(explode(',', $item->mentioned_user_ids)) : [];
            $idsByItem[$item->id] = $ids;
            foreach ($ids as $id) {
                $allMentionedIds[] = $id;
            }
        }

        $mentionedUsersById = collect();
        if (count($allMentionedIds) > 0) {
            $mentionedUsersById = Users::whereIn('id', array_unique($allMentionedIds))
                ->select(explode(',', Constants::userPublicFields))
                ->get()
                ->keyBy('id');
        }

        foreach ($items as $item) {
            $mentioned = [];
            foreach ($idsByItem[$item->id] as $id) {
                $mUser = $mentionedUsersById->get((int) $id);
                if ($mUser != null) {
                    $mentioned[] = $mUser;
                }
            }
            $item->mentionedUsers = collect($mentioned);
        }
    }

    public static function checkUserBlock($firstUserId, $secondUserId)
    {
        return UserBlocks::where(function ($query) use ($firstUserId, $secondUserId) {
            $query->where('from_user_id', $firstUserId)->where('to_user_id', $secondUserId);
        })
            ->orWhere(function ($query) use ($firstUserId, $secondUserId) {
                $query->where('from_user_id', $secondUserId)->where('to_user_id', $firstUserId);
            })
            ->exists();
    }

    public static function getUsersBlockedUsersIdsArray($userId)
    {
        $blockedUsers = UserBlocks::where('from_user_id', $userId)->pluck('to_user_id');
        $userBlockedMeIds = UserBlocks::where('to_user_id', $userId)->pluck('from_user_id');

        return array_merge($blockedUsers->toArray(), $userBlockedMeIds->toArray());
    }

    public static function settleHashtagPostCounts($hashtagId)
    {
        $hashtag = Hashtags::find($hashtagId);
        $hashtagText = $hashtag->hashtag;
        // Bound parameter — interpolating the tag allowed SQL injection.
        $hashtag->post_count = Posts::whereRaw("FIND_IN_SET(?,hashtags)", [$hashtagText])->count();
        $hashtag->save();
    }
    public static function settleSoundPostCounts($soundId)
    {
        $sound = Musics::find($soundId);
        $sound->post_count = Posts::where('sound_id', $soundId)->count();
        $sound->save();
    }
    public static function settleFollowCount($userId)
    {
        $user = Users::find($userId);
        $user->following_count = Followers::where('from_user_id', $userId)->count();
        $user->follower_count = Followers::where('to_user_id', $userId)->count();
        $user->save();
    }
    public static function settlePostCommentCount($postId)
    {
        $post = Posts::find($postId);
        $post->comments = PostComments::where('post_id', $postId)->count();
        $post->save();
    }
    public static function settlePostSaveCount($postId)
    {
        $post = Posts::find($postId);
        $post->saves = PostSaves::where('post_id', $postId)->count();
        $post->save();
    }
    public static function settleUserTotalPostLikesCount($userId)
    {
        $user = Users::find($userId);
        $user->total_post_likes_count = Posts::where('user_id', $userId)->sum('likes');
        $user->save();
    }
    public static function settlePostLikesCount($postId)
    {
        $post = Posts::find($postId);
        $post->likes = PostLikes::where('post_id', $postId)->count();
        $post->save();
    }
    public static function settleCommentsLikesCount($id)
    {
        $item = PostComments::find($id);
        $item->likes = CommentLikes::where('comment_id', $id)->count();
        $item->save();
    }
    public static function settleCommentsRepliesCount($id)
    {
        $item = PostComments::find($id);
        $item->replies_count = CommentReplies::where('comment_id', $id)->count();
        $item->save();
    }

    public static function generatePost($request, $postType, $user, $sound = null)
    {
        $post = new Posts();
        $post->user_id = $user->id;
        $post->post_type = $postType;
        $post->can_comment = $request->can_comment;
        if ($request->has('description')) {
            $post->description = $request->description;
        }
        if ($request->has('mentioned_user_ids')) {
            $post->mentioned_user_ids = GlobalFunction::cleanString($request->mentioned_user_ids);
        }
        if ($request->has('metadata')) {
            $post->metadata = $request->metadata;
        }
        // Location
        if ($request->has('place_title')) {
            $post->place_title = $request->place_title;
        }
        if ($request->has('place_lat')) {
            $post->place_lat = $request->place_lat;
        }
        if ($request->has('place_lon')) {
            $post->place_lon = $request->place_lon;
        }
        if ($request->has('state')) {
            $post->state = $request->state;
        }
        if ($request->has('country')) {
            $post->country = $request->country;
        }
        // Hashtags
        if ($request->has('hashtags')) {
            $post->hashtags = GlobalFunction::cleanString($request->hashtags);

            $hash_tag_array = explode(',', $request->hashtags);
            foreach ($hash_tag_array as $tag) {
                if ($tag != '') {
                    $hashtag = Hashtags::where('hashtag', $tag)->first();
                    if ($hashtag == null) {
                        $newHashtag = new Hashtags();
                        $newHashtag->hashtag = $tag;
                        $newHashtag->post_count = 1;
                        $newHashtag->save();
                    } else {
                        $hashtag->post_count = $hashtag->post_count + 1;
                        $hashtag->save();
                    }
                }
            }
        }

        // Reel & Feed Video : Video & Thumb
        if ($postType == Constants::postTypeReel || $postType == Constants::postTypeVideo) {
            $post->video = $request->video;
            $post->thumbnail = $request->thumbnail;
        }

        // sound check (Reels post only for now)
        if ($sound != null) {
            $sound->post_count += 1;
            $sound->save();
            $post->sound_id = $request->sound_id;
        }
        $post->save();

        // Kick off background HLS transcoding for reel/video posts. Publishing
        // is never blocked on this — the post is already saved above and
        // serves its raw `video` MP4 immediately; this job only upgrades it to
        // adaptive-bitrate HLS once transcoding finishes (see TranscodeVideoJob
        // and PollTranscodeStatus for why this is split into a fire-and-forget
        // dispatch + a separate polling step rather than one blocking job).
        //
        // Deliberately NOT dispatched to a named queue: production's worker
        // cron runs `queue:work --stop-when-empty --max-time=50` with no
        // `--queue=` flag, which only drains the 'default' queue (see
        // config/queue.php connections.database.queue). Dispatching to e.g.
        // onQueue('transcode') would silently never run without also editing
        // the crontab — not worth that risk for an isolation benefit we don't
        // need (the job's handle() itself only takes milliseconds, see
        // TranscodeVideoJob).
        if ($postType == Constants::postTypeReel || $postType == Constants::postTypeVideo) {
            TranscodeVideoJob::dispatch($post->id);
        }

        // Image Post
        if ($postType == Constants::postTypeImage) {
            foreach ($request->post_images as $image) {
                $postImage = new PostImages();
                $postImage->post_id = $post->id;
                $postImage->image = $image;
                $postImage->save();
            }
        }

        // Insert Notification Data : Post Mention
        if ($request->has('mentioned_user_ids')) {
            $mentionedUsers = Users::whereIn('id', explode(',', $post->mentioned_user_ids))->get();
            foreach ($mentionedUsers as $mUser) {
                GlobalFunction::insertUserNotification(Constants::notify_mention_post, $user->id, $mUser->id, $post->id);
                GlobalFunction::pushToUser(
                    $mUser->id,
                    $user->fullname ?? $user->username,
                    'mentioned you in a post',
                    ['type' => (string) Constants::notify_mention_post, 'id' => (string) $post->id],
                    'notify_mention'
                );
            }
        }

        GlobalFunction::publishPost($post, $user);

        $post = GlobalFunction::preparePostFullData($post->id);

        return $post;
    }

    /**
     * Single hook for "this post is now visible to other people".
     *
     * Enqueues the follower fan-out — one INSERT, so the upload response never
     * waits on it no matter how many followers the author has. posts:fanout
     * does the actual walking.
     */
    public static function publishPost($post, $author = null)
    {
        try {
            $authorId = $author->id ?? $post->user_id;

            // Unique post_id makes this idempotent, so a republish or retry
            // cannot notify the same followers twice.
            DB::table('post_fanout_queue')->insertOrIgnore([
                'post_id' => $post->id,
                'cursor_user_id' => 0,
                'total_sent' => 0,
                'status' => 0,
                'attempts' => 0,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            // Never let notification bookkeeping fail an upload.
            Log::error('publishPost fan-out enqueue failed for post ' . $post->id . ': ' . $e->getMessage());
        }
    }

    public static function checkIfUserCanPost($user)
    {
        if ($user->is_freez == 1) {
            return ['status' => false, 'message' => 'this user is freezed!'];
        }

        $globalSettings = GlobalSettings::first();
        $postsCount = Posts::where('user_id', $user->id)->whereDate('created_at', Carbon::today())->count();
        if ($postsCount >= $globalSettings->max_upload_daily) {
            return ['status' => false, 'message' => 'daily post upload limit exhausted!'];
        }
        return ['status' => true];
    }

    public static function saveFileInLocal($file, $filename)
    {
        if ($file != null) {
            $filePath = public_path('assets/img/' . $filename);
            $file->move(public_path('assets/img'), $filename);
            return $filePath;
        } else {
            return null;
        }
    }

    public static function prepareStoryFullData($id)
    {
        $item = Story::where('id', $id)
            ->with(['user:' . Constants::userPublicFields])
            ->with(['music'])
            ->first();

        return $item;
    }
    public static function preparePostFullData($id)
    {
        $item = Posts::where('id', $id)->with(Constants::postsWithArray)->first();

        if ($item != null) {
            $item->mentioned_users = Users::whereIn('id', explode(',', $item->mentioned_user_ids))->get();
        }

        return $item;
    }

    public static function prepareUserFullData($id)
    {
        return Users::with([
            'links',
            'stories' => function ($query) {
                $query->where('created_at', '>=', now()->subDay());
            },
            'stories.music',
        ])
            ->where('id', $id)
            ->first();
    }

    public static function cleanString($string)
    {
        return str_replace(['<', '>', '{', '}', '[', ']', '`'], '', $string);
    }
    public static function generateDummyUserIdentity()
    {
        $token = rand(100000, 999999);

        $first = GlobalFunction::generateRandomString(3);
        $first .= $token;
        $first .= GlobalFunction::generateRandomString(3);
        $count = Users::where('username', $first)->count();

        while ($count >= 1) {
            $token = rand(100000, 999999);
            $first = GlobalFunction::generateRandomString(3);
            $first .= $token;
            $first .= GlobalFunction::generateRandomString(3);
            $count = Users::where('username', $first)->count();
        }

        return $first;
    }
    /// Builds a handle from the user's display name.
    ///
    /// The clean name is tried first and digits are appended only on a real
    /// collision. This used to unconditionally append rand(1, 9999), so EVERY
    /// account ended up looking like "JaneDoe4821" even when "JaneDoe" was
    /// free — the "serial number in front of the user name" the client
    /// reported, since lists show the username as the primary line.
    public static function generateUsername($fullname)
    {
        // Strip anything that isn't safe in a handle, not just spaces.
        $base = preg_replace('/[^A-Za-z0-9._]/', '', (string) $fullname);
        $base = trim($base, '._');

        // Nothing usable left (e.g. a name written entirely in a non-Latin
        // script) — fall back to the random identity generator.
        if (strlen($base) < 3) {
            return GlobalFunction::generateDummyUserIdentity();
        }

        $base = substr($base, 0, 25);

        if (!Users::where('username', $base)->exists()) {
            return $base;
        }

        // Taken: walk a short counter before giving up on a readable handle.
        for ($suffix = 1; $suffix <= 999; $suffix++) {
            $candidate = $base . $suffix;
            if (!Users::where('username', $candidate)->exists()) {
                return $candidate;
            }
        }

        return GlobalFunction::generateDummyUserIdentity();
    }
    // Per-request memo: the authorizeUser middleware and the controller
    // action it guards both resolve the same token, which used to mean 2-3
    // identical queries per request. PHP-FPM/shared hosting tears the
    // process down between requests, so a static cache here is safely
    // request-scoped — it would NOT be safe under a long-lived worker
    // runtime (e.g. Octane) without clearing it between requests.
    private static array $userAuthTokenCache = [];

    public static function getUserFromAuthToken($token)
    {
        if ($token === null) {
            return null;
        }
        if (array_key_exists($token, self::$userAuthTokenCache)) {
            return self::$userAuthTokenCache[$token];
        }
        $userToken = UserAuthTokens::where('auth_token', $token)->first();
        $user = $userToken == null ? null : Users::find($userToken->user_id);
        return self::$userAuthTokenCache[$token] = $user;
    }

    // For endpoints that are open to guests (no/invalid auth token): returns the
    // real user when the token resolves, otherwise an UNSAVED sentinel with id 0.
    // id 0 matches no rows, so is_liked / is_saved / is_following / block checks
    // all evaluate to false without special-casing the query helpers.
    public static function getUserOrGuest($token)
    {
        $user = $token ? self::getUserFromAuthToken($token) : null;
        if ($user != null) {
            return $user;
        }
        $guest = new Users;
        $guest->id = 0;
        $guest->is_freez = 0;
        return $guest;
    }

    // Verifies a Google Sign-In ID token (issued to the app by google_sign_in)
    // and returns its payload array, or null when invalid. Accepts any client
    // id listed in GOOGLE_OAUTH_CLIENT_ID (comma-separated — the Android and
    // iOS clients can issue tokens with different aud values).
    public static function verifyGoogleIdToken($idToken)
    {
        try {
            $client = new Client();
            $payload = $client->verifyIdToken($idToken); // signature + expiry
            if (!$payload) {
                return null;
            }
            $allowedIds = array_filter(array_map('trim', explode(',', env('GOOGLE_OAUTH_CLIENT_ID', ''))));
            if (empty($allowedIds)) {
                Log::error('verifyGoogleIdToken: GOOGLE_OAUTH_CLIENT_ID is not configured; refusing to verify.');
                return null;
            }
            if (!in_array($payload['aud'] ?? '', $allowedIds)) {
                Log::error('verifyGoogleIdToken: aud not allowed: ' . ($payload['aud'] ?? 'null'));
                return null;
            }
            if (($payload['iss'] ?? null) !== 'https://accounts.google.com'
                && ($payload['iss'] ?? null) !== 'accounts.google.com') {
                return null;
            }
            // logInUser links a Google sign-in to an existing account by
            // email, so an address Google has not verified must never reach
            // that lookup. FILTER_VALIDATE_BOOLEAN because older token formats
            // carry the claim as the string "true".
            if (!filter_var($payload['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                Log::error('verifyGoogleIdToken: email not verified for sub ' . ($payload['sub'] ?? 'null'));
                return null;
            }
            return $payload;
        } catch (\Throwable $e) {
            Log::error('verifyGoogleIdToken: ' . $e->getMessage());
            return null;
        }
    }

    // Verifies a Sign in with Apple identityToken (JWT signed by Apple) and
    // returns its claims, or null when invalid. aud must be the iOS bundle id
    // or the Service ID used by the Android web flow.
    public static function verifyAppleIdentityToken($identityToken)
    {
        try {
            $jwks = Cache::remember('apple_auth_jwks', 3600, function () {
                $response = Http::timeout(10)->get('https://appleid.apple.com/auth/keys');
                return $response->successful() ? $response->json() : null;
            });
            if (!is_array($jwks)) {
                Log::error('verifyAppleIdentityToken: could not fetch Apple JWKS');
                return null;
            }

            $claims = JWT::decode($identityToken, JWK::parseKeySet($jwks, 'RS256'));

            $allowedAud = array_filter([env('APPLE_BUNDLE_ID'), env('APPLE_SERVICE_ID')]);
            if (($claims->iss ?? null) !== 'https://appleid.apple.com'
                || !in_array($claims->aud ?? '', $allowedAud)
                || empty($claims->sub)) {
                return null;
            }

            return $claims;
        } catch (\Throwable $e) {
            Log::error('verifyAppleIdentityToken: ' . $e->getMessage());
            return null;
        }
    }

    // Issues a 6-digit verification/reset code and emails it. Invalidates any
    // previous unconsumed code of the same type. Returns [status, message];
    // mail failure reports false but never throws (verification is soft).
    public static function issueVerificationCode($user, $email, $type)
    {
        $recentSends = VerificationCode::where('user_id', $user->id)
            ->where('type', $type)
            ->where('created_at', '>=', Carbon::now()->subHour())
            ->count();
        if ($recentSends >= VerificationCode::MAX_SENDS_PER_HOUR) {
            return [false, 'Too many codes requested, please try again later'];
        }

        VerificationCode::where('user_id', $user->id)
            ->where('type', $type)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => Carbon::now()]);

        $code = new VerificationCode();
        $code->user_id = $user->id;
        $code->email = $email;
        $code->code = strval(rand(100000, 999999));
        $code->type = $type;
        $code->expires_at = Carbon::now()->addMinutes(VerificationCode::EXPIRY_MINUTES);
        $code->save();

        try {
            // Queued (not ->send()): delivery must never block the HTTP
            // response. On the sync queue driver this still runs inline: the
            // real fix is QUEUE_CONNECTION=database + a queue:work cron.
            \Illuminate\Support\Facades\Mail::to($email)->queue(new VerificationCodeMail($code->code, $type));
        } catch (\Throwable $e) {
            Log::error('issueVerificationCode mail: ' . $e->getMessage());
            return [false, 'Could not send the email, please try again later'];
        }
        return [true, 'Code sent successfully'];
    }

    // Validates a submitted code; counts failed attempts and consumes the code
    // on success. Returns true only for a live, unconsumed, matching code.
    public static function consumeVerificationCode($user, $type, $submittedCode)
    {
        $code = VerificationCode::where('user_id', $user->id)
            ->where('type', $type)
            ->whereNull('consumed_at')
            ->orderBy('id', 'desc')
            ->first();
        if ($code == null || $code->expires_at < Carbon::now()
            || $code->attempts >= VerificationCode::MAX_ATTEMPTS) {
            return false;
        }
        if (!hash_equals($code->code, strval($submittedCode))) {
            $code->attempts += 1;
            $code->save();
            return false;
        }
        $code->consumed_at = Carbon::now();
        $code->save();
        return true;
    }

    // Compact profile in the shape of the app's AppUser model (note the
    // 'profile' key — not profile_photo). Embedded in chat/livestream
    // payloads to seed the client-side user cache.
    public static function appUserPayload($user)
    {
        if ($user == null) {
            return null;
        }
        // No 'identity': it is the account's email or phone number, and this
        // payload goes to every viewer of a livestream, signed-out guests
        // included. The app never reads it for other users.
        return [
            'user_id' => $user->id,
            'username' => $user->username,
            'fullname' => $user->fullname,
            'profile' => $user->profile_photo,
            'is_verify' => $user->is_verify,
        ];
    }

    // Single wallet transaction for gifting — shared by misc/sendGift (chat,
    // profile and post gifts) and livestream/sendStreamGift. Returns null on
    // success or an error message string. $context carries post_id /
    // livestream_id so a creator can see what each video or live earned.
    public static function transferGiftCoins($sender, $receiver, $gift, array $context = [])
    {
        if ($sender->id == $receiver->id) {
            return 'you can not gift yourself!';
        }
        // Dummy accounts are admin-made demo profiles nobody owns: a gift to
        // one is a fan's real money credited to no one.
        if ($receiver->is_dummy == 1) {
            return 'this account can not receive gifts';
        }
        if ($receiver->is_freez == 1) {
            return 'this account can not receive gifts right now';
        }
        if (self::checkUserBlock($sender->id, $receiver->id)) {
            return 'you can not send gifts to this user';
        }
        $price = (int) $gift->coin_price;

        // The sender's coins become the receiver's EARNINGS (their share of
        // what the coins cost), never the receiver's coins: coins are
        // spend-only, so a gift can no longer be cashed out at a rate the
        // platform never received. WalletLedger locks both rows, so N
        // simultaneous gifts cannot spend the same coins twice.
        if ($price > 0) {
            $split = WalletLedger::transferGift((int) $sender->id, (int) $receiver->id, $price, (int) $gift->id, $context);
            if ($split === null) {
                return 'no enough coins in your wallet!';
            }
        }

        self::insertUserNotification(Constants::notify_gift_user, $sender->id, $receiver->id, $gift->id);
        self::pushToUser(
            $receiver->id,
            $sender->fullname ?: $sender->username,
            'sent you a gift',
            ['type' => (string) Constants::notify_gift_user, 'id' => (string) $gift->id],
            'notify_gift_received'
        );
        return null;
    }

    public static function maskEmail($email)
    {
        $parts = explode('@', $email);
        if (count($parts) != 2 || strlen($parts[0]) < 2) {
            return $email;
        }
        return $parts[0][0] . str_repeat('*', max(strlen($parts[0]) - 2, 1))
            . substr($parts[0], -1) . '@' . $parts[1];
    }

    // Turns however a user typed their number ('0977 123 456', '977123456',
    // '+260977123456', '00260977123456') into digits-only international form
    // ('260977123456'). The web top-up pages have no country picker like the
    // app's signup does, and most users here type the local form or don't
    // know their country code at all.
    //
    // Assumes a national number never itself begins with the country code —
    // true wherever national numbers carry a trunk '0' (Zambia's 09…), which
    // is the case for app.default_country_code's intended markets.
    public static function canonicalMsisdn($input): ?string
    {
        $digits = preg_replace('/[^0-9]/', '', (string) $input);
        if ($digits === '') {
            return null;
        }
        // Dialled internationally as '00<code>…'.
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        $code = (string) config('app.default_country_code');
        if ($code !== '' && str_starts_with($digits, $code)) {
            return $digits;
        }
        $national = ltrim($digits, '0');
        return $national === '' ? null : $code . $national;
    }

    public static function generateRandomString($length)
    {
        $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    // Every extension an upload may be stored under. Uploads land in a folder
    // the web server serves directly, so the stored extension decides how the
    // file is treated: .php would execute, .html/.svg would run script on this
    // origin (where the admin panel lives). Media, plus the two admin asset
    // types (language CSVs, DeepAR effect files). Extend deliberately.
    private const UPLOAD_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', 'bmp', 'avif',
        'mp4', 'mov', 'm4v', '3gp', '3g2', 'webm', 'mkv', 'avi', 'mpeg', 'mpg',
        'mp3', 'm4a', 'aac', 'wav', 'ogg', 'oga', 'opus', 'amr', 'flac', 'weba',
        'csv', 'deepar',
    ];

    /// The extension an upload will be stored under, or null if it is not an
    /// allowed type. Trusts the client's extension only when it is on the
    /// list; otherwise falls back to sniffing the content (fileinfo), which
    /// rescues real media sent with an odd or missing extension.
    public static function safeUploadExtension($file): ?string
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (in_array($extension, self::UPLOAD_EXTENSIONS, true)) {
            return $extension;
        }
        try {
            $guessed = strtolower((string) $file->guessExtension());
        } catch (\Throwable $e) {
            $guessed = '';
        }
        return in_array($guessed, self::UPLOAD_EXTENSIONS, true) ? $guessed : null;
    }

    private static function cleanAppName(): string
    {
        // Clean APP_NAME (remove spaces and special characters)
        $rawAppName = env('APP_NAME');
        return $rawAppName ? preg_replace('/[^A-Za-z0-9_\-]/', '_', $rawAppName) : '';
    }

    /// $ownerId tags the stored name ("u42_…") for uploads a user may later
    /// delete through settings/deleteFile — see isOwnUpload(). The name is
    /// generated here, never taken from the client: the client's filename used
    /// to be kept verbatim, extension included, inside the public web root.
    public static function saveFileAndGivePath($file, ?int $ownerId = null)
    {
        $storageType = env('FILES_STORAGE_LOCATION');

        $extension = self::safeUploadExtension($file);
        if ($extension === null) {
            throw new \InvalidArgumentException(
                'Refusing to store an upload of an unsupported type (' . $file->getClientOriginalExtension() . ')'
            );
        }

        $cleanAppName = self::cleanAppName();

        $fileName = ($ownerId !== null ? 'u' . $ownerId . '_' : '')
            . time() . '_' . $cleanAppName . '_' . Str::random(16) . '.' . $extension;

        // Set base path with cleaned app name
        $appNamePath = $cleanAppName ? $cleanAppName . '/' : '';

        $filePath = $storageType === 'PUBLIC' ? 'uploads/' . $fileName : $appNamePath . 'uploads/' . $fileName;

        // Store file in the appropriate disk
        switch ($storageType) {
            case 'AWSS3':
                Storage::disk('s3')->put($filePath, file_get_contents($file), 'public');
                break;
            case 'DOSPACE':
                Storage::disk('digitalocean')->put($filePath, file_get_contents($file), 'public');
                break;
            case 'PUBLIC':
                Storage::disk('public')->put($filePath, file_get_contents($file), 'public');
                break;
            default:
                // No default branch here meant an unset or misspelled
                // FILES_STORAGE_LOCATION wrote nothing at all, yet still
                // returned a path — leaving rows pointing at files that were
                // never created, with no error anywhere. Fail loudly instead.
                throw new \RuntimeException(
                    'FILES_STORAGE_LOCATION is not set to a supported value ' .
                    '(expected AWSS3, DOSPACE or PUBLIC); got: ' . var_export($storageType, true)
                );
        }

        return $filePath;
    }

    /// True when $filePath is an upload this user made through
    /// settings/uploadFileGivePath: it sits directly in the uploads folder
    /// and carries their owner tag, which only saveFileAndGivePath writes.
    /// Paths a user merely references (e.g. typed into their own chat
    /// message) do not qualify, and neither does anything uploaded before
    /// owner tags existed.
    public static function isOwnUpload($filePath, int $userId): bool
    {
        if (!is_string($filePath) || $filePath === '' || str_contains($filePath, '..') || str_contains($filePath, '\\')) {
            return false;
        }
        $cleanAppName = self::cleanAppName();
        $uploadDirs = $cleanAppName !== '' ? ['uploads', $cleanAppName . '/uploads'] : ['uploads'];
        if (!in_array(dirname($filePath), $uploadDirs, true)) {
            return false;
        }
        return preg_match('/^u' . $userId . '_[A-Za-z0-9_\-]+\.[a-z0-9]+$/', basename($filePath)) === 1;
    }

    public static function deleteFile($filePath)
    {
        $storageType = env('FILES_STORAGE_LOCATION');

        switch ($storageType) {
            case 'AWSS3':
                // Check and delete the file from AWS S3
                if (Storage::disk('s3')->exists($filePath)) {
                    Storage::disk('s3')->delete($filePath);
                }
                break;
            case 'DOSPACE':
                // Check and delete the file from DigitalOcean Spaces
                if (Storage::disk('digitalocean')->exists($filePath)) {
                    Storage::disk('digitalocean')->delete($filePath);
                }
                break;
            case 'PUBLIC':
                // Check and delete the file from local storage
                if (Storage::disk('local')->exists('public/' . $filePath)) {
                    Storage::disk('local')->delete('public/' . $filePath);
                }
                break;
        }
    }

    /**
     * Removes a whole HLS ladder given its master playlist path.
     *
     * hls_path is "hls/{postId}/master.m3u8" (PollTranscodeStatus), and the
     * segments and variant playlists sit alongside it — deleting just the
     * master would strand every .ts file, which is most of the bytes.
     */
    public static function deleteHlsDirectory($hlsPath)
    {
        if (empty($hlsPath)) {
            return;
        }

        $directory = dirname($hlsPath);
        if ($directory === '' || $directory === '.' || $directory === '/') {
            return;
        }

        // Same disk mapping PollTranscodeStatus uploads the ladder to.
        $disk = GlobalFunction::resolveStorageDisk();
        if ($disk === null) {
            return;
        }

        try {
            if (Storage::disk($disk)->exists($directory)) {
                Storage::disk($disk)->deleteDirectory($directory);
            }
        } catch (\Throwable $e) {
            // Never let orphaned media block a purge run.
            Log::error('deleteHlsDirectory failed for ' . $directory . ': ' . $e->getMessage());
        }
    }

    public static function getItemBaseUrl()
    {
        $storageType = env('FILES_STORAGE_LOCATION');
        switch ($storageType) {
            case 'AWSS3':
                return env('AWS_ITEM_BASE_URL');
            case 'DOSPACE':
                return env('DO_SPACE_URL');
            case 'PUBLIC':
                return env('APP_URL') . 'public/storage/';
        }
    }
    // The Laravel disk name (config/filesystems.php) matching whichever
    // storage backend FILES_STORAGE_LOCATION currently selects — pulled out
    // of the saveFileAndGivePath/deleteFile switch so PollTranscodeStatus can
    // upload a completed HLS rendition tree to the same place everything
    // else already goes, without duplicating the mapping a third time.
    public static function resolveStorageDisk(): ?string
    {
        return match (env('FILES_STORAGE_LOCATION')) {
            'AWSS3' => 's3',
            'DOSPACE' => 'digitalocean',
            'PUBLIC' => 'public',
            default => null,
        };
    }

    public static function generateFileUrl($filePath)
    {
        if ($filePath != null) {
            $storageType = env('FILES_STORAGE_LOCATION');
            switch ($storageType) {
                case 'AWSS3':
                    return env('AWS_ITEM_BASE_URL') . $filePath;
                case 'DOSPACE':
                    return env('DO_SPACE_URL') . $filePath;
                case 'PUBLIC':
                    return env('APP_URL') . 'public/storage/' . $filePath;
            }
        }else{
            return null;
        }
    }

    public static function sendSimpleResponse($status, $msg)
    {
        return response()->json(['status' => $status, 'message' => $msg]);
    }

    public static function sendDataResponse($status, $msg, $data)
    {
        return response()->json(['status' => $status, 'message' => $msg, 'data' => $data]);
    }

    /**
     * Every admin DataTable formats its dates through here, and each one runs
     * inside a ->map() over the whole page of rows — so one column that came
     * back as a raw string (a model missing a `datetime` cast) threw and took
     * the entire listing down with a 500, which reads in the browser as an
     * empty table rather than as an error. Parse instead of assuming Carbon.
     */
    public static function formateDatabaseTime($time)
    {
        if ($time === null || $time === '') {
            return '';
        }

        if (!$time instanceof \DateTimeInterface) {
            try {
                $time = Carbon::parse($time);
            } catch (\Throwable $e) {
                return '';
            }
        }

        return $time->format('d M Y');
    }

    public static function generateUserAuthToken($user)
    {
        // Multiple concurrent sessions are allowed (one token per device/login);
        // logout removes only the token being used, password reset removes all.
        $token = new UserAuthTokens();
        $token->user_id = $user->id;
        $token->auth_token = Crypt::encryptString($user->identity . Carbon::now());
        $token->save();

        return $token;
    }

    public static function formatDescription($description)
    {
        // Escaped FIRST. Every caller renders the result as HTML — admin
        // tables, and fetchFormattedPostDesc through jQuery .html() — and the
        // text is whatever the user typed into a caption, comment or reply.
        // Only the mention/hashtag links below are markup we write ourselves.
        $description = e($description);

        // Use regex to find all mentions of @<id>
        $pattern = '/@(\d+)/';

        // Callback function to replace user IDs with usernames and add a URL
        $description = preg_replace_callback(
            $pattern,
            function ($matches) {
                $userId = $matches[1]; // Extract the user ID from the match
                // Fetch the username corresponding to the user ID
                $username = Users::where('id', $userId)->value('username');

                if ($username) {
                    // Generate a URL for the user and wrap the username in an anchor tag
                    $url = route('viewUserDetails', $userId);
                    return '<a target="_blank" href="' . e($url) . '" class="username">@' . e($username) . '</a>';
                }

                // If the user is not found, return the original mention
                return $matches[0];
            },
            $description,
        );

        // Make hashtags clickable with a URL. (?<!&) keeps this off the "#039"
        // inside the &#039; entity that e() writes for an apostrophe.
        $description = preg_replace_callback(
            '/(?<!&)#(\w+)/',
            function ($matches) {
                $hashtag = $matches[1]; // Extract the hashtag
                // Generate a URL for the hashtag and wrap it in an anchor tag
                $url = route('hashtagDetails', $hashtag);
                return '<a target="_blank" href="' . e($url) . '" class="hashtag">#' . e($hashtag) . '</a>';
            },
            $description,
        );

        return $description;
    }

    public static function getPhoneCountryCodes()
    {
        $filePath = public_path('assets/csv/phone_country_codes.csv'); // Update with your file name
        $csvData = [];

        if (($handle = fopen($filePath, 'r')) !== false) {
            $headers = fgetcsv($handle); // Read the first row as headers

            while (($row = fgetcsv($handle)) !== false) {
                $csvData[] = array_combine($headers, $row);
            }
            fclose($handle);
        }

        return $csvData;
    }
}

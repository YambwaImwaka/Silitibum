<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\CoinTopUpRequests;
use App\Models\GlobalFunction;
use App\Models\Livestream;
use App\Models\LivestreamComment;
use App\Models\LivestreamUser;
use App\Models\PostSaves;
use App\Models\Posts;
use App\Models\RedeemRequests;
use App\Models\Story;
use App\Models\UserAuthTokens;
use App\Models\UserBlocks;
use App\Models\Users;
use App\Models\VerificationCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Permanent admin-side account deletion.
//
// The heavy lifting is delegated to GlobalFunction::deleteUserAccount(), the
// same routine the in-app "delete my account" flow uses. That is deliberate:
// it does not merely delete rows, it also settles the denormalised counters
// that hang off them — follower counts on the people this user followed,
// like/comment counts on other people's posts, hashtag post counts and sound
// post counts — plus per-post notification cleanup and post image files. A
// parallel implementation that only issued DELETEs would leave every one of
// those counters overstated, and the damage would show up on OTHER users'
// profiles rather than anywhere obvious.
//
// This class adds what that routine does not cover: money records, sessions,
// chat, livestreams, blocks, and the uploaded files that are not post images.
class AccountDeleter
{
    /**
     * @return array{posts:int,files:int}
     */
    public static function delete(Users $user): array
    {
        $userId = $user->id;

        // Collect file paths BEFORE anything is deleted — afterwards there is
        // no row left to tell us what to unlink.
        //
        // Post media is deliberately NOT collected here any more. Posts are
        // soft-deleted and kept for the retention window (see
        // GlobalFunction::softDeletePost) so a takedown can still be reviewed
        // if it is disputed — otherwise deleting your account would be the
        // one way to erase content you had been reported for. Unlinking the
        // video/thumbnail now would leave those retained rows pointing at
        // files that no longer exist; posts:purge-retention removes the rows
        // and their media together once the window expires.
        $files = [];
        if (!empty($user->profile_photo)) {
            $files[] = $user->profile_photo;
        }
        $postCount = Posts::where('user_id', $userId)->count();
        foreach (Story::where('user_id', $userId)->pluck('content') as $content) {
            if (!empty($content)) {
                $files[] = $content;
            }
        }

        DB::transaction(function () use ($userId, $user) {
            // De-identify money records FIRST. Two reasons this must precede
            // the shared routine: that routine deletes RedeemRequests
            // outright, and once user_id is null its where('user_id', $id)
            // no longer matches — so this both preserves the audit trail and
            // neutralises the delete, without editing shared code that the
            // in-app deletion flow also depends on.
            CoinTopUpRequests::where('user_id', $userId)->update(['user_id' => null]);
            RedeemRequests::where('user_id', $userId)->update(['user_id' => null]);

            // Posts, comments, likes, follows, stories, links, reports,
            // notifications — and every counter they feed.
            GlobalFunction::deleteUserAccount($user);

            // --- not covered by the shared routine ---
            // Their saves of OTHER people's posts (saves ON their own posts
            // are handled per-post by deleteAllPostData).
            PostSaves::where('user_id', $userId)->delete();
            UserBlocks::where('from_user_id', $userId)->orWhere('to_user_id', $userId)->delete();
            ChatMessage::where('sender_id', $userId)->delete();
            LivestreamUser::where('user_id', $userId)->delete();
            LivestreamComment::where('sender_id', $userId)
                ->orWhere('receiver_id', $userId)->delete();
            Livestream::where('host_id', $userId)->delete();
            // Sessions and pending codes — a live token outliving the account
            // would keep authenticating requests for a user that no longer exists.
            UserAuthTokens::where('user_id', $userId)->delete();
            VerificationCode::where('user_id', $userId)->delete();

            // No Eloquent models for these.
            DB::table('user_supports')->where('user_id', $userId)->delete();
            DB::table('dummy_live_videos')->where('user_id', $userId)->delete();
            DB::table('oauth_access_tokens')->where('user_id', $userId)->delete();

            // DELIBERATELY KEPT: tbl_sound. Music this user uploaded may be
            // attached to other people's posts via tbl_post.sound_id, so
            // removing it would silently break audio on content that is not
            // theirs. deleteAllPostData() already re-settles the sound's post
            // count for each post that goes away.

            $user->delete();
        });

        // After commit, never inside: a late rollback would otherwise leave
        // the rows intact but the files already gone — unrecoverable. The
        // worst case this way is orphaned files, which is only wasted disk.
        $filesDeleted = 0;
        foreach (array_unique($files) as $path) {
            try {
                GlobalFunction::deleteFile($path);
                $filesDeleted++;
            } catch (\Throwable $e) {
                Log::warning("AccountDeleter: could not delete file '{$path}' for user {$userId}: {$e->getMessage()}");
            }
        }

        Log::info("AccountDeleter: deleted user {$userId} ({$postCount} posts, {$filesDeleted} files)");

        return ['posts' => $postCount, 'files' => $filesDeleted];
    }
}

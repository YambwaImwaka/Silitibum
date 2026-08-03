<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\CoinTopUpRequests;
use App\Models\CommentLikes;
use App\Models\CommentReplies;
use App\Models\Followers;
use App\Models\GlobalFunction;
use App\Models\Livestream;
use App\Models\LivestreamComment;
use App\Models\LivestreamUser;
use App\Models\PostComments;
use App\Models\PostImages;
use App\Models\PostLikes;
use App\Models\PostSaves;
use App\Models\Posts;
use App\Models\RedeemRequests;
use App\Models\ReportPosts;
use App\Models\ReportUsers;
use App\Models\Story;
use App\Models\UserAuthTokens;
use App\Models\UserBlocks;
use App\Models\UserLinks;
use App\Models\UserNotification;
use App\Models\Users;
use App\Models\VerificationCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Permanently removes a user and everything attached to them.
//
// Two deliberate exceptions to "delete everything":
//   1. Coin top-ups and redeem/withdrawal requests are KEPT with user_id
//      nulled. They are money records — if a chargeback or reconciliation
//      question arrives after the account is gone, deleting them leaves no
//      way to answer it. Nulling the reference de-identifies the row while
//      preserving the amount/provider/reference trail.
//   2. Nothing here is soft-deleted. tbl_users.identity carries a UNIQUE
//      index, so leaving the row behind would permanently block that email
//      or phone from ever registering again — which is not what a user
//      asking to be deleted expects.
//
// There is no FK cascade in this schema (tables merely carry a user_id
// column), so every table has to be handled explicitly. Anything missed
// becomes an orphan row pointing at an id that no longer exists.
class AccountDeleter
{
    /**
     * @return array{posts:int,files:int} counts for the admin flash message
     */
    public static function delete(Users $user): array
    {
        $userId = $user->id;
        $filesDeleted = 0;
        $postCount = 0;

        // Collect file paths BEFORE the rows go away — once the posts are
        // deleted there is no way to find out what to unlink from storage.
        $files = [];
        if (!empty($user->profile_photo)) {
            $files[] = $user->profile_photo;
        }

        $posts = Posts::where('user_id', $userId)->get();
        $postCount = $posts->count();
        $postIds = $posts->pluck('id')->all();

        foreach ($posts as $post) {
            foreach (['video', 'thumbnail'] as $field) {
                if (!empty($post->$field)) {
                    $files[] = $post->$field;
                }
            }
        }

        if (!empty($postIds)) {
            foreach (PostImages::whereIn('post_id', $postIds)->pluck('image') as $image) {
                if (!empty($image)) {
                    $files[] = $image;
                }
            }
        }

        foreach (Story::where('user_id', $userId)->pluck('content') as $content) {
            if (!empty($content)) {
                $files[] = $content;
            }
        }

        DB::transaction(function () use ($userId, $postIds, $user) {
            // --- money: de-identify, never destroy (see class docblock) ---
            CoinTopUpRequests::where('user_id', $userId)->update(['user_id' => null]);
            RedeemRequests::where('user_id', $userId)->update(['user_id' => null]);

            // --- everything the user's own posts pulled in with them ---
            if (!empty($postIds)) {
                PostImages::whereIn('post_id', $postIds)->delete();
                PostLikes::whereIn('post_id', $postIds)->delete();
                PostSaves::whereIn('post_id', $postIds)->delete();
                ReportPosts::whereIn('post_id', $postIds)->delete();

                // Comment likes/replies hang off comments, so they have to go
                // before the comments themselves or they are orphaned.
                $commentIds = PostComments::whereIn('post_id', $postIds)->pluck('id')->all();
                if (!empty($commentIds)) {
                    CommentLikes::whereIn('comment_id', $commentIds)->delete();
                    CommentReplies::whereIn('comment_id', $commentIds)->delete();
                }
                PostComments::whereIn('post_id', $postIds)->delete();
                Posts::whereIn('id', $postIds)->delete();
            }

            // --- the user's activity on OTHER people's content ---
            PostLikes::where('user_id', $userId)->delete();
            PostSaves::where('user_id', $userId)->delete();
            CommentLikes::where('user_id', $userId)->delete();
            CommentReplies::where('user_id', $userId)->delete();
            PostComments::where('user_id', $userId)->delete();

            // --- social graph (both directions) ---
            Followers::where('from_user_id', $userId)->orWhere('to_user_id', $userId)->delete();
            UserBlocks::where('from_user_id', $userId)->orWhere('to_user_id', $userId)->delete();

            // --- reports filed BY and ABOUT this user ---
            ReportUsers::where('user_id', $userId)->delete();

            // --- livestreams ---
            LivestreamUser::where('user_id', $userId)->delete();
            LivestreamComment::where('sender_id', $userId)
                ->orWhere('receiver_id', $userId)->delete();
            Livestream::where('host_id', $userId)->delete();

            // --- chat, stories, notifications, links, sessions ---
            ChatMessage::where('sender_id', $userId)->delete();
            Story::where('user_id', $userId)->delete();
            UserNotification::where('to_user_id', $userId)
                ->orWhere('from_user_id', $userId)->delete();
            UserLinks::where('user_id', $userId)->delete();
            UserAuthTokens::where('user_id', $userId)->delete();
            VerificationCode::where('user_id', $userId)->delete();

            // No Eloquent models exist for these three.
            DB::table('user_supports')->where('user_id', $userId)->delete();
            DB::table('dummy_live_videos')->where('user_id', $userId)->delete();
            // Legacy Passport table still carrying ~1k rows; leaving these
            // behind would let a stale token outlive the account it belonged to.
            DB::table('oauth_access_tokens')->where('user_id', $userId)->delete();

            // DELIBERATELY NOT DELETED: tbl_sound. Music this user uploaded may
            // be attached to other people's posts via tbl_post.sound_id, and
            // removing it would silently break audio on content that isn't
            // theirs. The sound outlives the uploader on purpose.

            $user->delete();
        });

        // Storage cleanup runs AFTER the transaction commits. Doing it inside
        // would mean a late rollback leaves the DB intact but the files
        // already gone — unrecoverable. This way the worst case is orphaned
        // files, which is merely wasted disk.
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

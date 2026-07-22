<?php

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

use App\Http\Controllers\BroadcastAuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\CronsController;
use App\Http\Controllers\HashtagController;
use App\Http\Controllers\LivestreamApiController;
use App\Http\Controllers\ModeratorController;
use App\Http\Controllers\MusicController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PostsController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StoryController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

// Group routes with common middleware
Route::middleware('checkHeader')->group(function () {

    // Users
    Route::prefix('user')->group(function () {
        Route::post('logInUser', [UserController::class, 'logInUser'])->middleware('throttle:auth');
        Route::post('logOutUser', [UserController::class, 'logOutUser']);

        // MySQL-native auth (post-Firebase)
        Route::post('registerUser', [UserController::class, 'registerUser'])->middleware('throttle:auth');
        Route::post('forgotPassword', [UserController::class, 'forgotPassword'])->middleware('throttle:auth');
        Route::post('resetPasswordWithCode', [UserController::class, 'resetPasswordWithCode'])->middleware('throttle:auth');
        Route::post('sendEmailVerificationCode', [UserController::class, 'sendEmailVerificationCode'])->middleware('authorizeUser');
        Route::post('verifyEmailCode', [UserController::class, 'verifyEmailCode'])->middleware('authorizeUser');
        Route::post('changePassword', [UserController::class, 'changePassword'])->middleware('authorizeUser');
        Route::post('updateUserDetails', [UserController::class, 'updateUserDetails'])->middleware('authorizeUser');
        Route::post('addUserLink', [UserController::class, 'addUserLink'])->middleware('authorizeUser');
        Route::post('deleteUserLink', [UserController::class, 'deleteUserLink'])->middleware('authorizeUser');
        Route::post('editeUserLink', [UserController::class, 'editeUserLink'])->middleware('authorizeUser');
        Route::post('updateLastUsedAt', [UserController::class, 'updateLastUsedAt'])->middleware('authorizeUser');
        Route::post('checkUsernameAvailability', [UserController::class, 'checkUsernameAvailability'])->middleware('authorizeUser');

        // Actions (Block, Follow etc)
        Route::post('blockUser', [UserController::class, 'blockUser'])->middleware('authorizeUser');
        Route::post('unBlockUser', [UserController::class, 'unBlockUser'])->middleware('authorizeUser');

        // Fetch
        Route::post('fetchMyBlockedUsers', [UserController::class, 'fetchMyBlockedUsers'])->middleware('authorizeUser');
        // Guest-open: viewing a profile needs no session (controller uses getUserOrGuest)
        Route::post('fetchUserDetails', [UserController::class, 'fetchUserDetails']);

        // Followers
        Route::post('followUser', [UserController::class, 'followUser'])->middleware('authorizeUser');
        Route::post('unFollowUser', [UserController::class, 'unFollowUser'])->middleware('authorizeUser');
        // Guest-open: public follower/following lists (controller uses getUserOrGuest)
        Route::post('fetchUserFollowers', [UserController::class, 'fetchUserFollowers']);
        Route::post('fetchUserFollowings', [UserController::class, 'fetchUserFollowings']);

        Route::post('fetchMyFollowers', [UserController::class, 'fetchMyFollowers'])->middleware('authorizeUser');
        Route::post('fetchMyFollowings', [UserController::class, 'fetchMyFollowings'])->middleware('authorizeUser');

        // Guest-open: user search (controller uses getUserOrGuest)
        Route::post('searchUsers', [UserController::class, 'searchUsers']);

        // Delete My Account
        Route::post('deleteMyAccount', [UserController::class, 'deleteMyAccount'])->middleware('authorizeUser');


    });

    // Posts
    Route::prefix('post')->group(function () {

        // Add Post
        Route::post('addUserMusic', [PostsController::class, 'addUserMusic'])->middleware('authorizeUser');
        Route::post('addPost_Reel', [PostsController::class, 'addPost_Reel'])->middleware('authorizeUser');
        Route::post('addPost_Feed_Video', [PostsController::class, 'addPost_Feed_Video'])->middleware('authorizeUser');
        Route::post('addPost_Feed_Image', [PostsController::class, 'addPost_Feed_Image'])->middleware('authorizeUser');
        Route::post('addPost_Feed_Text', [PostsController::class, 'addPost_Feed_Text'])->middleware('authorizeUser');

        // Musics — browse/search open to guests (controllers use getUserOrGuest)
        Route::post('serchMusic', [MusicController::class, 'serchMusic']);
        Route::post('fetchMusicExplore', [MusicController::class, 'fetchMusicExplore']);
        Route::post('fetchMusicByCategories', [MusicController::class, 'fetchMusicByCategories']);
        Route::post('fetchSavedMusics', [MusicController::class, 'fetchSavedMusics'])->middleware('authorizeUser');

        // Like, Share, Save
        Route::post('likePost', [PostsController::class, 'likePost'])->middleware('authorizeUser');
        Route::post('disLikePost', [PostsController::class, 'disLikePost'])->middleware('authorizeUser');
        // Guest-open: view/share counters (no user context used; guests count too)
        Route::post('increaseViewsCount', [PostsController::class, 'increaseViewsCount']);
        Route::post('increaseShareCount', [PostsController::class, 'increaseShareCount']);
        Route::post('savePost', [PostsController::class, 'savePost'])->middleware('authorizeUser');
        Route::post('unSavePost', [PostsController::class, 'unSavePost'])->middleware('authorizeUser');

        // Comment
        Route::post('addPostComment', [CommentController::class, 'addPostComment'])->middleware('authorizeUser');
        Route::post('fetchCommentById', [CommentController::class, 'fetchCommentById'])->middleware('authorizeUser');
        Route::post('fetchCommentByReplyId', [CommentController::class, 'fetchCommentByReplyId'])->middleware('authorizeUser');
        Route::post('likeComment', [CommentController::class, 'likeComment'])->middleware('authorizeUser');
        Route::post('disLikeComment', [CommentController::class, 'disLikeComment'])->middleware('authorizeUser');
        Route::post('deleteComment', [CommentController::class, 'deleteComment'])->middleware('authorizeUser');
        // Pin/Unpin comment
        Route::post('pinComment', [CommentController::class, 'pinComment'])->middleware('authorizeUser');
        Route::post('unPinComment', [CommentController::class, 'unPinComment'])->middleware('authorizeUser');
        // Pin/Pin Post
        Route::post('pinPost', [PostsController::class, 'pinPost'])->middleware('authorizeUser');
        Route::post('unpinPost', [PostsController::class, 'unpinPost'])->middleware('authorizeUser');

        // Comment Reply
        Route::post('replyToComment', [CommentController::class, 'replyToComment'])->middleware('authorizeUser');
        Route::post('deleteCommentReply', [CommentController::class, 'deleteCommentReply'])->middleware('authorizeUser');

        // Guest-open: reading comments (controllers use getUserOrGuest)
        Route::post('fetchPostComments', [CommentController::class, 'fetchPostComments']);
        Route::post('fetchPostCommentReplies', [CommentController::class, 'fetchPostCommentReplies']);

        // Fetch — feeds/content browsing open to guests (controllers use getUserOrGuest).
        // fetchPostsFollowing and fetchSavedPosts stay auth-only (meaningless without a session).
        Route::post('fetchPostById', [PostsController::class, 'fetchPostById']);
        Route::post('fetchPostsDiscover', [PostsController::class, 'fetchPostsDiscover']);
        Route::post('fetchPostsFollowing', [PostsController::class, 'fetchPostsFollowing'])->middleware('authorizeUser');
        Route::post('fetchReelPostsByMusic', [PostsController::class, 'fetchReelPostsByMusic']);
        Route::post('fetchPostsByHashtag', [PostsController::class, 'fetchPostsByHashtag']);
        Route::post('fetchUserPosts', [PostsController::class, 'fetchUserPosts']);
        Route::post('fetchSavedPosts', [PostsController::class, 'fetchSavedPosts'])->middleware('authorizeUser');
        Route::post('fetchExplorePageData', [PostsController::class, 'fetchExplorePageData']);
        Route::post('fetchPostsByLocation', [PostsController::class, 'fetchPostsByLocation']);
        Route::post('fetchPostsNearBy', [PostsController::class, 'fetchPostsNearBy']);

        // Search — open to guests
        Route::post('searchPosts', [PostsController::class, 'searchPosts']);
        Route::post('searchHashtags', [HashtagController::class, 'searchHashtags'])->middleware('authorizeUser');

        // Delete Post
        Route::post('deletePost', [PostsController::class, 'deletePost'])->middleware('authorizeUser');

        // Stories
        Route::post('createStory', [StoryController::class, 'createStory'])->middleware('authorizeUser');
        Route::post('viewStory', [StoryController::class, 'viewStory'])->middleware('authorizeUser');
        Route::post('fetchStory', [StoryController::class, 'fetchStory'])->middleware('authorizeUser');
        Route::post('deleteStory', [StoryController::class, 'deleteStory'])->middleware('authorizeUser');
        // Guest-open: story deep links (controller uses getUserOrGuest)
        Route::post('fetchStoryByID', [StoryController::class, 'fetchStoryByID']);


    });

    // Misc
    Route::prefix('misc')->group(function () {

        // Gifts
        Route::post('sendGift', [WalletController::class, 'sendGift'])->middleware('authorizeUser');
        Route::post('submitWithdrawalRequest', [WalletController::class, 'submitWithdrawalRequest'])->middleware('authorizeUser');
        Route::post('fetchMyWithdrawalRequest', [WalletController::class, 'fetchMyWithdrawalRequest'])->middleware('authorizeUser');
        Route::post('buyCoins', [WalletController::class, 'buyCoins'])->middleware('authorizeUser');


        // Report
        Route::post('reportPost', [ReportController::class, 'reportPost'])->middleware('authorizeUser');
        Route::post('reportUser', [ReportController::class, 'reportUser'])->middleware('authorizeUser');

        // Notification
        Route::post('fetchAdminNotifications', [NotificationController::class, 'fetchAdminNotifications'])->middleware('authorizeUser');
        Route::post('pushNotificationToSingleUser', [NotificationController::class, 'pushNotificationToSingleUser'])->middleware('authorizeUser');
        Route::post('fetchActivityNotifications', [NotificationController::class, 'fetchActivityNotifications'])->middleware('authorizeUser');

    });

    // Misc
    Route::prefix('moderator')->group(function () {
        Route::post('moderator_freezeUser', [ModeratorController::class, 'moderator_freezeUser'])->middleware('authorizeUser');
        Route::post('moderator_unFreezeUser', [ModeratorController::class, 'moderator_unFreezeUser'])->middleware('authorizeUser');
        Route::post('moderator_deletePost', [ModeratorController::class, 'moderator_deletePost'])->middleware('authorizeUser');
        Route::post('moderator_deleteStory', [ModeratorController::class, 'moderator_deleteStory'])->middleware('authorizeUser');

    });

    // Settings Routes
    Route::prefix('settings')->group(function () {
        Route::post('fetchSettings', [SettingsController::class, 'fetchSettings']);
        Route::post('uploadFileGivePath', [SettingsController::class, 'uploadFileGivePath'])->middleware('authorizeUser');
        Route::post('deleteFile', [SettingsController::class, 'deleteFile'])->middleware('authorizeUser');
    });

    // Chat (MySQL + realtime broadcasting)
    Route::prefix('chat')->group(function () {
        Route::post('fetchThreads', [ChatController::class, 'fetchThreads'])->middleware('authorizeUser');
        Route::post('fetchUnreadCounts', [ChatController::class, 'fetchUnreadCounts'])->middleware('authorizeUser');
        Route::post('fetchMessages', [ChatController::class, 'fetchMessages'])->middleware('authorizeUser');
        Route::post('sendMessage', [ChatController::class, 'sendMessage'])->middleware('authorizeUser');
        Route::post('markThreadRead', [ChatController::class, 'markThreadRead'])->middleware('authorizeUser');
        Route::post('acceptChatRequest', [ChatController::class, 'acceptChatRequest'])->middleware('authorizeUser');
        Route::post('rejectChatRequest', [ChatController::class, 'rejectChatRequest'])->middleware('authorizeUser');
        Route::post('unsendMessage', [ChatController::class, 'unsendMessage'])->middleware('authorizeUser');
        Route::post('deleteMessageForMe', [ChatController::class, 'deleteMessageForMe'])->middleware('authorizeUser');
        Route::post('deleteThread', [ChatController::class, 'deleteThread'])->middleware('authorizeUser');
    });

    // Livestream signalling (MySQL + presence channels; Zego carries media)
    Route::prefix('livestream')->group(function () {
        // Guest-open: the Live tab lists streams without a session
        Route::post('fetchLivestreams', [LivestreamApiController::class, 'fetchLivestreams']);
        Route::post('createLivestream', [LivestreamApiController::class, 'createLivestream'])->middleware('authorizeUser');
        Route::post('joinLivestream', [LivestreamApiController::class, 'joinLivestream'])->middleware('authorizeUser');
        Route::post('leaveLivestream', [LivestreamApiController::class, 'leaveLivestream'])->middleware('authorizeUser');
        Route::post('fetchStreamState', [LivestreamApiController::class, 'fetchStreamState'])->middleware('authorizeUser');
        Route::post('sendComment', [LivestreamApiController::class, 'sendComment'])->middleware('authorizeUser');
        Route::post('addLikes', [LivestreamApiController::class, 'addLikes'])->middleware('authorizeUser');
        Route::post('sendStreamGift', [LivestreamApiController::class, 'sendStreamGift'])->middleware('authorizeUser');
        Route::post('updateUserState', [LivestreamApiController::class, 'updateUserState'])->middleware('authorizeUser');
        Route::post('updateBattleState', [LivestreamApiController::class, 'updateBattleState'])->middleware('authorizeUser');
        Route::post('registerFollowGained', [LivestreamApiController::class, 'registerFollowGained'])->middleware('authorizeUser');
        Route::post('endLivestream', [LivestreamApiController::class, 'endLivestream'])->middleware('authorizeUser');
    });

    // Pusher-protocol channel authorization (custom authtoken scheme)
    Route::post('broadcasting/auth', [BroadcastAuthController::class, 'authenticate']);
});

// Misc
Route::prefix('cron')->group(function () {
    Route::get('reGeneratePlaceApiToken', [CronsController::class, 'reGeneratePlaceApiToken']);
    Route::get('deleteExpiredStories', [CronsController::class, 'deleteExpiredStories']);
    Route::get('deleteOldNotifications', [CronsController::class, 'deleteOldNotifications']);
    Route::get('countDailyActiveUsers', [CronsController::class, 'countDailyActiveUsers']);


    // Don't use below function on your live environment, This will delete data from the platform. For Author only
    Route::get('cleanDemoAppData', [CronsController::class, 'cleanDemoAppData']);
});

// Comment below route once the reset process is finished
// Route::get('resetAdminPanelPassword', [SettingsController::class, 'resetAdminPanelPassword']);


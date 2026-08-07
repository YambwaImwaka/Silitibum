<?php

namespace App\Http\Controllers;

use App\Models\CoinTopUpRequests;
use App\Models\Constants;
use App\Models\DailyActiveUsers;
use App\Models\Followers;
use App\Models\GlobalFunction;
use App\Models\GlobalSettings;
use App\Models\Livestream;
use App\Models\LivestreamComment;
use App\Models\LivestreamUser;
use App\Models\PostComments;
use App\Models\PostLikes;
use App\Models\Posts;
use App\Models\PostSaves;
use App\Models\RedeemRequests;
use App\Models\ReportPosts;
use App\Models\ReportUsers;
use App\Models\Users;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    //
    function dashboard()
    {
        // Validate googleCredentials.json file
        try {
            $filePath = base_path('googleCredentials.json');
            if (!File::exists($filePath)) {
                return response()->json(['message' => 'The googleCredentials.json file does not exist.'], 404);
            }

            $contents = File::get($filePath);
            if (empty(trim($contents))) {
                return response()->view('google_credentials_empty');
            }

            // Validate JSON format
            $json = json_decode($contents, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return response()->json(['message' => 'The googleCredentials.json file contains invalid JSON.'], 400);
            }

        } catch (\Exception $e) {
            return response()->json(['message' => 'An error occurred: ' . $e->getMessage()], 500);
        }

        // These 14 counts were previously run uncached on every dashboard load.
        // A 2 minute TTL is the same staleness tolerance the settings payload
        // already accepts (SettingsController::fetchSettings), and summary
        // counters do not need to be second-accurate.
        $counts = Cache::remember('dashboard_summary_counts', now()->addMinutes(2), function () {
            // Post counts by type in one grouped query instead of four COUNT(*)s.
            $postsByType = Posts::selectRaw('post_type, COUNT(*) as aggregate')
                ->groupBy('post_type')
                ->pluck('aggregate', 'post_type');

            // Same for redeem requests: one grouped query covers every status,
            // including Processing (3) and Failed (4) which the old tile total
            // silently dropped.
            $withdrawalsByStatus = RedeemRequests::selectRaw('status, COUNT(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status');

            return [
                'userTotal' => Users::count(),
                'userFreezed' => Users::where('is_freez', 1)->count(),
                'userModerator' => Users::where('is_moderator', 1)->count(),
                'userDummy' => Users::where('is_dummy', 1)->count(),

                'postsTotal' => (int) $postsByType->sum(),
                'postsReels' => (int) ($postsByType[Constants::postTypeReel] ?? 0),
                'postsVideos' => (int) ($postsByType[Constants::postTypeVideo] ?? 0),
                'postsImages' => (int) ($postsByType[Constants::postTypeImage] ?? 0),
                'postsText' => (int) ($postsByType[Constants::postTypeText] ?? 0),

                'reportsPost' => ReportPosts::count(),
                'reportsUser' => ReportUsers::count(),

                'withdrawalPending' => (int) ($withdrawalsByStatus[Constants::withdrawalPending] ?? 0),
                'withdrawalCompleted' => (int) ($withdrawalsByStatus[Constants::withdrawalCompleted] ?? 0),
                'withdrawalRejected' => (int) ($withdrawalsByStatus[Constants::withdrawalRejected] ?? 0),
                'withdrawalProcessing' => (int) ($withdrawalsByStatus[Constants::withdrawalProcessing] ?? 0),
                'withdrawalFailed' => (int) ($withdrawalsByStatus[Constants::withdrawalFailed] ?? 0),
            ];
        });

        return view('dashboard')->with($counts);
    }

    function fetchChartData(Request $request){

            $month = $request->month;
            $year = $request->year;

            $datesWithCount = $this->monthlySeries($year, $month, 'dashboard_chart', function ($startDate, $endDate) {
                // One grouped query per series. This used to be a day-by-day
                // loop issuing 2-3 queries per day (~90 per month view).
                $usersByDay = Users::whereBetween('created_at', [$startDate, $endDate])
                    ->selectRaw('DATE(created_at) as d, COUNT(*) as c')
                    ->groupBy('d')
                    ->pluck('c', 'd');

                $postsByDay = Posts::whereBetween('created_at', [$startDate, $endDate])
                    ->selectRaw('DATE(created_at) as d, COUNT(*) as c')
                    ->groupBy('d')
                    ->pluck('c', 'd');

                $dauByDay = DailyActiveUsers::whereBetween('date', [$startDate, $endDate])
                    ->selectRaw('DATE(`date`) as d, user_count as c')
                    ->pluck('c', 'd');

                // Today has no daily_active_users row yet (the cron writes
                // yesterday's), so it is the one day still read live.
                $todayDau = Users::whereDate('app_last_used_at', Carbon::today())->count();

                return compact('usersByDay', 'postsByDay', 'dauByDay', 'todayDau');
            }, function ($date, $key, $series) {
                return [
                    'date' => $key,
                    'usersCount' => (int) ($series['usersByDay'][$key] ?? 0),
                    'postsCount' => (int) ($series['postsByDay'][$key] ?? 0),
                    'dauCount' => $date->isToday()
                        ? (int) $series['todayDau']
                        : (int) ($series['dauByDay'][$key] ?? 0),
                ];
            });

            return GlobalFunction::sendDataResponse(true, 'Users fetched successfully.', $datesWithCount);
    }

    /**
     * Daily likes / comments / follows / saves.
     *
     * These are all real rows with a created_at (unlike tbl_post.views, which is
     * only a denormalised counter), so they are the engagement history the
     * platform actually has.
     */
    function fetchEngagementChartData(Request $request)
    {
        $data = $this->monthlySeries($request->year, $request->month, 'dashboard_engagement', function ($startDate, $endDate) {
            return [
                'likes' => $this->dailyCounts(PostLikes::query(), $startDate, $endDate),
                'comments' => $this->dailyCounts(PostComments::query(), $startDate, $endDate),
                'follows' => $this->dailyCounts(Followers::query(), $startDate, $endDate),
                'saves' => $this->dailyCounts(PostSaves::query(), $startDate, $endDate),
            ];
        }, function ($date, $key, $series) {
            return [
                'date' => $key,
                'likes' => (int) ($series['likes'][$key] ?? 0),
                'comments' => (int) ($series['comments'][$key] ?? 0),
                'follows' => (int) ($series['follows'][$key] ?? 0),
                'saves' => (int) ($series['saves'][$key] ?? 0),
            ];
        });

        return GlobalFunction::sendDataResponse(true, 'Engagement fetched successfully.', $data);
    }

    /**
     * Daily money in vs money out, plus an *estimate* of livestream gifting.
     *
     * Top-ups and payouts are exact — both have their own request tables. Gift
     * volume is not: there is no coin-transaction ledger anywhere in the schema
     * (GlobalFunction::transferGiftCoins mutates counters on tbl_users in place
     * and records nothing), so the only durable trace of a gift is the
     * livestream comment row that announced it. That covers livestream gifting
     * only, not gifts sent on posts — hence "estimate", and the UI labels it so.
     */
    function fetchRevenueChartData(Request $request)
    {
        $currency = GlobalSettings::first()?->currency ?? '';

        $series = $this->monthlySeries($request->year, $request->month, 'dashboard_revenue', function ($startDate, $endDate) {
            $topUps = CoinTopUpRequests::whereBetween('created_at', [$startDate, $endDate])
                ->where('status', 'completed')
                ->selectRaw('DATE(created_at) as d, SUM(amount) as c')
                ->groupBy('d')
                ->pluck('c', 'd');

            // tbl_redeem_request.amount is a varchar (it predates the automated
            // payout providers). MySQL would coerce it inside SUM() anyway, but
            // an explicit CAST keeps a non-numeric value from silently
            // contributing 0 to a money figure without anyone noticing.
            $payouts = RedeemRequests::whereBetween('created_at', [$startDate, $endDate])
                ->where('status', Constants::withdrawalCompleted)
                ->selectRaw('DATE(created_at) as d, SUM(CAST(amount AS DECIMAL(12,2))) as c')
                ->groupBy('d')
                ->pluck('c', 'd');

            $giftCoins = LivestreamComment::whereBetween('livestream_comments.created_at', [$startDate, $endDate])
                ->where('comment_type', 'GIFT')
                ->join('tbl_gifts', 'tbl_gifts.id', '=', 'livestream_comments.gift_id')
                ->selectRaw('DATE(livestream_comments.created_at) as d, SUM(tbl_gifts.coin_price) as c')
                ->groupBy('d')
                ->pluck('c', 'd');

            return compact('topUps', 'payouts', 'giftCoins');
        }, function ($date, $key, $series) {
            return [
                'date' => $key,
                'topUps' => round((float) ($series['topUps'][$key] ?? 0), 2),
                'payouts' => round((float) ($series['payouts'][$key] ?? 0), 2),
                'giftCoins' => (int) ($series['giftCoins'][$key] ?? 0),
            ];
        });

        return GlobalFunction::sendDataResponse(true, 'Revenue fetched successfully.', [
            'currency' => $currency,
            'series' => $series,
        ]);
    }

    /**
     * Livestream analytics page.
     *
     * The admin panel had no visibility into real streams at all — the only
     * livestream screen was "Dummy Lives", which lists promo placeholders. The
     * livestreams / livestream_users / livestream_comments tables carry session
     * durations, coins earned per stream and gift events, none of which was
     * surfaced anywhere.
     */
    function livestreamAnalytics()
    {
        $summary = Cache::remember('dashboard_livestream_summary', now()->addMinutes(2), function () {
            $sessionSeconds = $this->avgSessionSecondsExpression();

            return [
                'liveNow' => Livestream::where('status', 'live')->count(),
                'streamsTotal' => Livestream::count(),
                'battlesTotal' => Livestream::where('type', 'BATTLE')->count(),
                'coinsLifetime' => (int) LivestreamUser::sum('live_coin'),
                'giftEventsTotal' => LivestreamComment::where('comment_type', 'GIFT')->count(),
                'avgSessionSeconds' => (int) round((float) LivestreamUser::whereNotNull('left_at')
                    ->whereNotNull('join_stream_time')
                    ->where('join_stream_time', '>', 0)
                    ->selectRaw("AVG({$sessionSeconds}) as aggregate")
                    ->value('aggregate')),
                'topHosts' => LivestreamUser::where('livestream_users.type', 'HOST')
                    ->join('tbl_users', 'tbl_users.id', '=', 'livestream_users.user_id')
                    ->selectRaw('tbl_users.id, tbl_users.username, tbl_users.fullname, tbl_users.profile_photo,
                                 SUM(livestream_users.live_coin) as coins,
                                 COUNT(*) as streams,
                                 SUM(livestream_users.followers_gained) as followers_gained')
                    ->groupBy('tbl_users.id', 'tbl_users.username', 'tbl_users.fullname', 'tbl_users.profile_photo')
                    ->orderByDesc('coins')
                    ->limit(10)
                    ->get(),
                // Ended streams keep a single captured frame — the broadcast
                // itself is never recorded — so this is the only record of
                // what actually went out.
                'recentStreams' => Livestream::orderByDesc('id')
                    ->limit(12)
                    ->get(['id', 'host_id', 'description', 'snapshot', 'status', 'watching_count', 'created_at'])
                    ->map(function ($stream) {
                        $host = Users::find($stream->host_id);
                        $stream->host_username = $host->username ?? '';
                        $stream->host_fullname = $host->fullname ?? '';
                        // Formatted here rather than in the view: compiled
                        // Blade runs in the global namespace and there is no
                        // GlobalFunction alias registered, so calling it from
                        // the template would not resolve.
                        $stream->created_at_label =
                            GlobalFunction::formateDatabaseTime($stream->created_at);
                        return $stream;
                    }),
            ];
        });

        return view('livestream_analytics')->with($summary + [
            'baseUrl' => GlobalFunction::getItemBaseUrl(),
        ]);
    }

    function fetchLivestreamChartData(Request $request)
    {
        $data = $this->monthlySeries($request->year, $request->month, 'dashboard_livestream', function ($startDate, $endDate) {
            $streams = $this->dailyCounts(Livestream::query(), $startDate, $endDate);

            $giftEvents = $this->dailyCounts(
                LivestreamComment::where('comment_type', 'GIFT'),
                $startDate,
                $endDate
            );

            $coins = LivestreamUser::whereBetween('created_at', [$startDate, $endDate])
                ->selectRaw('DATE(created_at) as d, SUM(live_coin) as c')
                ->groupBy('d')
                ->pluck('c', 'd');

            $sessionSeconds = $this->avgSessionSecondsExpression();
            $avgSession = LivestreamUser::whereBetween('created_at', [$startDate, $endDate])
                ->whereNotNull('left_at')
                ->whereNotNull('join_stream_time')
                ->where('join_stream_time', '>', 0)
                ->selectRaw("DATE(created_at) as d, AVG({$sessionSeconds}) as c")
                ->groupBy('d')
                ->pluck('c', 'd');

            return compact('streams', 'giftEvents', 'coins', 'avgSession');
        }, function ($date, $key, $series) {
            return [
                'date' => $key,
                'streams' => (int) ($series['streams'][$key] ?? 0),
                'giftEvents' => (int) ($series['giftEvents'][$key] ?? 0),
                'coins' => (int) ($series['coins'][$key] ?? 0),
                // Minutes reads better than seconds on a chart axis.
                'avgSessionMinutes' => round(((float) ($series['avgSession'][$key] ?? 0)) / 60, 1),
            ];
        });

        return GlobalFunction::sendDataResponse(true, 'Livestream stats fetched successfully.', $data);
    }

    /**
     * Session length in seconds.
     *
     * The two ends of a session are stored in different units:
     * livestream_users.join_stream_time is epoch MILLISECONDS in a bigint,
     * while left_at is a real datetime. Subtracting them directly yields
     * nonsense, so join_stream_time has to be converted first.
     */
    private function avgSessionSecondsExpression(): string
    {
        return 'TIMESTAMPDIFF(SECOND, FROM_UNIXTIME(join_stream_time / 1000), left_at)';
    }

    /** One grouped count-per-day query for any table with a created_at. */
    private function dailyCounts($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('DATE(created_at) as d, COUNT(*) as c')
            ->groupBy('d')
            ->pluck('c', 'd');
    }

    /**
     * Runs $loader once for the whole month, then walks the calendar filling a
     * dense day-by-day series via $row so days with no rows come back as 0
     * instead of being absent. Shared by every dashboard chart endpoint.
     *
     * The result is cached per chart/year/month. Today's bucket is still inside
     * that cache, so a chart including today can lag by up to the TTL — an
     * acceptable trade for admin summary charts, and the same tolerance
     * dashboard_summary_counts takes.
     */
    private function monthlySeries($year, $month, string $cacheKey, callable $loader, callable $row): array
    {
        $year = (int) $year;
        $month = (int) $month;

        // Guard against a malformed month/year from the client turning into a
        // Carbon::create() exception or an absurd date range.
        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
            $now = Carbon::now();
            $year = $now->year;
            $month = $now->month;
        }

        return Cache::remember("{$cacheKey}_{$year}_{$month}", now()->addMinutes(5), function () use ($year, $month, $loader, $row) {
            $startDate = Carbon::create($year, $month, 1)->startOfDay();
            $endDate = Carbon::create($year, $month, 1)->endOfMonth()->endOfDay();

            $series = $loader($startDate, $endDate);

            $out = [];
            for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
                $out[] = $row($date, $date->format('Y-m-d'), $series);
            }

            return $out;
        });
    }
}

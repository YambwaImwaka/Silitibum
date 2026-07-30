<?php

namespace App\Services;

use App\Models\Constants;
use App\Models\Followers;
use App\Models\PostLikes;
use App\Models\Posts;
use App\Models\PostSaves;
use App\Models\Users;
use Illuminate\Support\Collection;

/**
 * Ranks the "For You" feed.
 *
 * Replaces what used to be a bare `inRandomOrder()` in
 * PostsController::fetchPostsDiscover — the feed was literally unpersonalised.
 *
 * The three primary signals, in the order they matter:
 *
 *  1. LOCATION  — how close the post's author is to the viewer. Read from
 *     tbl_users.country/region/city, which IP geolocation populates for
 *     essentially every user, rather than tbl_post.place_* which is an
 *     optional thing a poster tags by hand and is mostly null.
 *  2. INTERESTS — overlap between the candidate's hashtags/sound and the
 *     hashtags/sounds of what this user has actually liked and saved.
 *  3. TYPE PREFERENCE — whether the candidate's post_type is the type this
 *     user engages with (someone who only ever likes Snaps should not be fed
 *     text posts).
 *
 * Recency, engagement rate and raw popularity are deliberately *secondary*:
 * they decide ordering among posts that already fit the viewer, instead of
 * letting a handful of globally-popular posts dominate every feed.
 *
 * Cost is a fixed ~6 queries per request regardless of pool size: every signal
 * is loaded in one batched query keyed on the candidate pool, never per-post.
 */
class FeedRankingService
{
    /** How many posts to score before taking the requested page. */
    const CANDIDATE_POOL_SIZE = 220;

    /** Recent-window segment of the pool. */
    const RECENT_SEGMENT_SIZE = 150;
    const RECENT_WINDOW_DAYS = 14;

    /** Evergreen high-engagement segment, so a quiet week is not an empty feed. */
    const TOP_SEGMENT_SIZE = 70;

    /** A post loses half its recency credit every this-many hours. */
    const RECENCY_HALFLIFE_HOURS = 72;

    /** How much history to read when learning what someone is into. */
    const AFFINITY_HISTORY_SIZE = 200;
    const TYPE_HISTORY_SIZE = 300;

    // Primary signals.
    const W_LOCATION = 0.25;
    const W_AFFINITY = 0.25;
    const W_TYPE_PREFERENCE = 0.15;
    // Social + quality tiebreakers.
    const W_FOLLOW = 0.15;
    const W_ENGAGEMENT = 0.10;
    const W_RECENCY = 0.07;
    const W_POPULARITY = 0.02;
    // tbl_post.is_trending is never written anywhere in the codebase today.
    // Kept at a token weight so that if something starts setting it, it helps
    // immediately without a code change.
    const W_TRENDING = 0.01;

    /**
     * Random nudge, so two consecutive loads for the same user are not a
     * deterministic re-run of the same ordering. Large enough to shuffle
     * near-equal posts, too small to override a genuinely better match.
     */
    const JITTER_MAX = 0.15;

    /** Same-city / same-region / same-country credit. */
    const LOC_CITY = 1.0;
    const LOC_REGION = 0.6;
    const LOC_COUNTRY = 0.3;

    /** Extra credit when a post is *tagged* near the viewer (place_lat/lon). */
    const LOC_NEARBY_BONUS = 0.2;
    const LOC_NEARBY_KM = 50;

    /**
     * Builds the candidate pool.
     *
     * Two segments rather than one query, because neither alone works: pure
     * recency starves a feed whose newest posts are all poor, and pure
     * all-time-top would show the same canon forever. Note this never runs
     * ORDER BY RAND() over the table — that was the old implementation's main
     * cost, and it grew with the post count.
     *
     * @param  array  $postTypes     Constants::postType* values to include.
     * @param  array  $blockedUserIds
     * @param  array  $excludeIds    Already-served post ids (client cursor).
     */
    public function fetchCandidates(array $postTypes, array $blockedUserIds, array $excludeIds = []): Collection
    {
        $postTypes = array_values(array_filter(array_map('intval', $postTypes)));
        if (empty($postTypes)) {
            $postTypes = [Constants::postTypeReel];
        }

        $recent = $this->baseCandidateQuery($postTypes, $blockedUserIds, $excludeIds)
            ->where('created_at', '>=', now()->subDays(self::RECENT_WINDOW_DAYS))
            ->orderByDesc('id')
            ->limit(self::RECENT_SEGMENT_SIZE)
            ->get();

        $top = $this->baseCandidateQuery(
            $postTypes,
            $blockedUserIds,
            // Don't re-fetch what the recent segment already holds.
            array_merge($excludeIds, $recent->pluck('id')->all())
        )
            ->orderByRaw('(COALESCE(likes,0) + COALESCE(comments,0) * 2 + COALESCE(shares,0) * 3) DESC')
            ->limit(self::TOP_SEGMENT_SIZE)
            ->get();

        return $recent->concat($top)->take(self::CANDIDATE_POOL_SIZE);
    }

    private function baseCandidateQuery(array $postTypes, array $blockedUserIds, array $excludeIds)
    {
        $query = Posts::query()
            // Only the columns scoring actually reads. The full row (description,
            // metadata, hashtags blobs) is re-fetched with relations for just the
            // winning page, back in the controller.
            ->select([
                'id', 'user_id', 'post_type', 'sound_id', 'hashtags', 'views',
                'likes', 'comments', 'saves', 'shares', 'is_trending',
                'place_lat', 'place_lon', 'created_at',
            ])
            ->whereIn('post_type', $postTypes)
            ->whereHas('user', function ($q) {
                $q->where('is_freez', 0);
            })
            // Author location for the location signal — one join, not a lookup
            // per candidate.
            ->with(['user:id,country,countryCode,region,regionName,city,lat,lon']);

        if (!empty($blockedUserIds)) {
            $query->whereNotIn('user_id', $blockedUserIds);
        }
        if (!empty($excludeIds)) {
            $query->whereNotIn('id', $excludeIds);
        }

        return $query;
    }

    /**
     * Scores the pool for $user and returns the best $limit, highest first.
     */
    public function rank(Collection $candidates, Users $user, int $limit): Collection
    {
        if ($candidates->isEmpty()) {
            return $candidates;
        }

        $isGuest = empty($user->id);

        $affinity = $isGuest ? ['hashtags' => [], 'sounds' => []] : $this->loadAffinity($user->id);
        $typeWeights = $isGuest ? [] : $this->loadTypePreference($user->id);
        $followedAuthorIds = $isGuest
            ? []
            : $this->loadFollowedAuthors($user->id, $candidates->pluck('user_id')->unique()->all());

        // Engagement rate and popularity only mean something relative to the
        // rest of the pool, so both are min/max normalised across it.
        $engagementRaw = [];
        $popularityRaw = [];
        foreach ($candidates as $post) {
            $views = max((int) $post->views, 1);
            $weighted = (int) $post->likes
                + (int) $post->comments * 2
                + (int) $post->saves * 2
                + (int) $post->shares * 3;
            $engagementRaw[$post->id] = $weighted / $views;
            $popularityRaw[$post->id] = log10(
                (int) $post->views
                + (int) $post->likes * 2
                + (int) $post->comments * 3
                + (int) $post->shares * 4
                + 1
            );
        }
        $engagement = $this->normalise($engagementRaw);
        $popularity = $this->normalise($popularityRaw);

        $now = now();

        return $candidates->map(function ($post) use (
            $user, $isGuest, $affinity, $typeWeights, $followedAuthorIds,
            $engagement, $popularity, $now
        ) {
            $ageHours = max($post->created_at ? $now->diffInHours($post->created_at, true) : 0, 0);

            $score = self::W_LOCATION * $this->locationScore($post, $user)
                + self::W_AFFINITY * $this->affinityScore($post, $affinity)
                + self::W_TYPE_PREFERENCE * $this->typeScore($post, $typeWeights, $isGuest)
                + self::W_FOLLOW * (in_array((int) $post->user_id, $followedAuthorIds, true) ? 1 : 0)
                + self::W_ENGAGEMENT * ($engagement[$post->id] ?? 0)
                + self::W_RECENCY * exp(-$ageHours / self::RECENCY_HALFLIFE_HOURS)
                + self::W_POPULARITY * ($popularity[$post->id] ?? 0)
                + self::W_TRENDING * ((int) $post->is_trending === 1 ? 1 : 0)
                + mt_rand() / mt_getrandmax() * self::JITTER_MAX;

            $post->feed_score = $score;

            return $post;
        })
            ->sortByDesc('feed_score')
            ->take($limit)
            ->values();
    }

    /**
     * Graded location match between the candidate's author and the viewer.
     *
     * Returns 0 (not a penalty) when either side has no location on file —
     * guests and users whose geolocation lookup failed should still get a feed,
     * just one decided by the other signals.
     */
    private function locationScore($post, Users $user): float
    {
        $author = $post->user;
        if (!$author) {
            return 0.0;
        }

        $score = 0.0;

        // Compare on countryCode where both sides have it: `country` is a free
        // text display name and the same place can arrive spelled differently.
        $sameCountry = $this->matches($user->countryCode, $author->countryCode)
            || $this->matches($user->country, $author->country);

        if ($sameCountry) {
            if ($this->matches($user->city, $author->city)) {
                $score = self::LOC_CITY;
            } elseif ($this->matches($user->region, $author->region)
                || $this->matches($user->regionName, $author->regionName)) {
                $score = self::LOC_REGION;
            } else {
                $score = self::LOC_COUNTRY;
            }
        }

        // Post-level tagged coordinates are sparse (optional at post time), so
        // they only ever add on top of the author-location signal.
        if ($post->place_lat && $post->place_lon && $user->lat && $user->lon) {
            $distance = $this->haversineKm(
                (float) $user->lat, (float) $user->lon,
                (float) $post->place_lat, (float) $post->place_lon
            );
            if ($distance <= self::LOC_NEARBY_KM) {
                $score = min(1.0, $score + self::LOC_NEARBY_BONUS);
            }
        }

        return $score;
    }

    /**
     * Fraction of the candidate's hashtags the user has shown interest in,
     * plus credit for a sound they have engaged with before.
     */
    private function affinityScore($post, array $affinity): float
    {
        $tags = $this->splitHashtags($post->hashtags);

        $tagScore = 0.0;
        if (!empty($tags) && !empty($affinity['hashtags'])) {
            $hits = 0;
            foreach ($tags as $tag) {
                if (isset($affinity['hashtags'][$tag])) {
                    $hits++;
                }
            }
            $tagScore = $hits / count($tags);
        }

        $soundScore = ($post->sound_id && isset($affinity['sounds'][(int) $post->sound_id])) ? 1.0 : 0.0;

        // Hashtags describe the topic, so they lead; a shared sound is a weaker
        // hint (trending audio gets used across unrelated topics).
        return min(1.0, $tagScore * 0.75 + $soundScore * 0.25);
    }

    /**
     * 1.0 for the type this user engages with most, scaled down for the rest.
     * New users with no like history get a flat 0.5 — neutral, so the signal
     * neither helps nor hurts until there is something to learn from.
     */
    private function typeScore($post, array $typeWeights, bool $isGuest): float
    {
        if ($isGuest || empty($typeWeights)) {
            return 0.5;
        }

        $max = max($typeWeights);
        if ($max <= 0) {
            return 0.5;
        }

        return ($typeWeights[(int) $post->post_type] ?? 0) / $max;
    }

    /**
     * Hashtags and sounds from this user's last N likes and saves.
     * Two queries, both bounded.
     */
    private function loadAffinity(int $userId): array
    {
        $likedPostIds = PostLikes::where('user_id', $userId)
            ->orderByDesc('id')
            ->limit(self::AFFINITY_HISTORY_SIZE)
            ->pluck('post_id');

        $savedPostIds = PostSaves::where('user_id', $userId)
            ->orderByDesc('id')
            ->limit(self::AFFINITY_HISTORY_SIZE)
            ->pluck('post_id');

        $postIds = $likedPostIds->concat($savedPostIds)->unique()->filter()->all();
        if (empty($postIds)) {
            return ['hashtags' => [], 'sounds' => []];
        }

        $rows = Posts::whereIn('id', $postIds)
            ->select(['hashtags', 'sound_id'])
            ->get();

        $hashtags = [];
        $sounds = [];
        foreach ($rows as $row) {
            foreach ($this->splitHashtags($row->hashtags) as $tag) {
                $hashtags[$tag] = true;
            }
            if ($row->sound_id) {
                $sounds[(int) $row->sound_id] = true;
            }
        }

        return ['hashtags' => $hashtags, 'sounds' => $sounds];
    }

    /**
     * How many of this user's recent likes went to each post_type.
     * One grouped query over a bounded subquery.
     */
    private function loadTypePreference(int $userId): array
    {
        $recentLikes = PostLikes::where('user_id', $userId)
            ->orderByDesc('id')
            ->limit(self::TYPE_HISTORY_SIZE)
            ->pluck('post_id')
            ->filter()
            ->all();

        if (empty($recentLikes)) {
            return [];
        }

        return Posts::whereIn('id', $recentLikes)
            ->selectRaw('post_type, COUNT(*) as aggregate')
            ->groupBy('post_type')
            ->pluck('aggregate', 'post_type')
            ->mapWithKeys(fn($count, $type) => [(int) $type => (int) $count])
            ->all();
    }

    /**
     * Which of the pool's authors this user follows — one query against the
     * pool's author ids, not a check per candidate.
     */
    private function loadFollowedAuthors(int $userId, array $authorIds): array
    {
        if (empty($authorIds)) {
            return [];
        }

        return Followers::where('from_user_id', $userId)
            ->whereIn('to_user_id', $authorIds)
            ->pluck('to_user_id')
            ->map(fn($id) => (int) $id)
            ->all();
    }

    /** Scales values into 0..1. A pool where every value is equal maps to 0. */
    private function normalise(array $values): array
    {
        if (empty($values)) {
            return [];
        }

        $min = min($values);
        $max = max($values);
        $range = $max - $min;

        if ($range <= 0) {
            return array_map(fn() => 0.0, $values);
        }

        return array_map(fn($v) => ($v - $min) / $range, $values);
    }

    /** tbl_post.hashtags is a comma-separated string (FIND_IN_SET elsewhere). */
    private function splitHashtags(?string $hashtags): array
    {
        if (empty($hashtags)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(fn($t) => mb_strtolower(trim($t)), explode(',', $hashtags)),
            fn($t) => $t !== ''
        )));
    }

    /** Case-insensitive equality that treats blanks as "no data", not a match. */
    private function matches($a, $b): bool
    {
        if (empty($a) || empty($b)) {
            return false;
        }

        return mb_strtolower(trim((string) $a)) === mb_strtolower(trim((string) $b));
    }

    private function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}

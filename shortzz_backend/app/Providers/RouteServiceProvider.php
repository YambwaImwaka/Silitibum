<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * This is used by Laravel authentication to redirect users after login.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * The controller namespace for the application.
     *
     * When present, controller route declarations will automatically be prefixed with this namespace.
     *
     * @var string|null
     */
    // protected $namespace = 'App\\Http\\Controllers';

    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::prefix('api')
                ->middleware('api')
                ->namespace($this->namespace)
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->namespace($this->namespace)
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by(optional($request->user())->id ?: $request->ip());
        });

        // Tighter limit for login/register/forgot-password/reset-code — these
        // are keyed by IP (no auth token exists yet) and are exactly what a
        // brute-force/credential-stuffing/code-guessing attempt would hit
        // hardest, so they need a much lower ceiling than general API traffic.
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // View/share recording is guest-open (signed-out watching still
        // counts) and now writes a row per event, so it needs a ceiling of its
        // own. Generous enough for real scrolling — a viewer swiping steadily
        // through the reel feed fires roughly one every three seconds — but
        // low enough that the endpoint is not a free write amplifier. The
        // per-viewer-per-day unique index already caps what any of this can
        // actually change.
        RateLimiter::for('engagement', function (Request $request) {
            return Limit::perMinute(120)->by($request->header('authtoken') ?: $request->ip());
        });

        // Client-requested pushes (NotificationController::
        // pushNotificationToSingleUser). Legitimate use is at most two per
        // live-stream start plus one at signup; anything beyond that is
        // someone spamming their followers.
        RateLimiter::for('push', function (Request $request) {
            return Limit::perMinute(10)->by($request->header('authtoken') ?: $request->ip());
        });

        // Saving a payout account asks the payout provider whose name a
        // number is registered to. Keyed on the USER (not the IP — Zambian
        // carrier NAT puts whole neighbourhoods behind one address, and not
        // the token — one user can hold several), so nobody can turn it into
        // a name-lookup service.
        RateLimiter::for('payout-account', function (Request $request) {
            $user = \App\Models\GlobalFunction::getUserFromAuthToken($request->header('authtoken'));
            return Limit::perDay(5)->by('payout-account:' . ($user->id ?? $request->ip()));
        });

        // Admin panel login. Its own bucket rather than 'auth', so app users
        // signing in from the same network can't lock the admin out.
        RateLimiter::for('admin-login', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });
    }
}

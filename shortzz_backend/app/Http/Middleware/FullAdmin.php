<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

// Admin actions that move money or change what it is worth (coin and earnings
// adjustments, payouts, prices, payout provider keys) are for full admins
// only. The panel's "tester" accounts (tbl_admin.user_type 0) were held back
// only by a browser-side check (checkUserType in app.js), so anyone logged in
// as a tester could call these endpoints directly. Runs after checkLogin.
class FullAdmin
{
    public function handle(Request $request, Closure $next)
    {
        if ((int) Session::get('user_type') !== 1) {
            return new JsonResponse([
                'status' => false,
                'message' => "You are Tester. You can't make this change!",
            ], 403);
        }
        return $next($request);
    }
}

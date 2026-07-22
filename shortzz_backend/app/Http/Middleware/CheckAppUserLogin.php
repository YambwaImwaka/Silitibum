<?php

namespace App\Http\Middleware;

use App\Models\Users;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

class CheckAppUserLogin
{
    public function handle(Request $request, Closure $next)
    {
        $userId = Session::get('app_user_id');
        $user = $userId ? Users::find($userId) : null;
        if ($user == null || $user->is_freez == 1) {
            Session::pull('app_user_id');
            return redirect(route('topup.login'));
        }
        $request->attributes->set('appUser', $user);
        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\GlobalFunction;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AuthorizeUser
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        if (isset($_SERVER['HTTP_AUTHTOKEN'])) {
            $auth_token = $_SERVER['HTTP_AUTHTOKEN'];

            // Resolving (rather than a standalone token lookup) primes
            // GlobalFunction's per-request memo, so the controller action
            // this guards gets its user for free instead of re-querying.
            $user = GlobalFunction::getUserFromAuthToken($auth_token);

            if ($user != null) {
                return $next($request);
            } else {
                $data['status']    = false;
                $data['meassage'] = "Unauthorized Access";
                $data['reason'] = "Invalid Token!";
                return new JsonResponse($data, 401);
            }
        } else {
            $data['status']    = false;
            $data['meassage'] = "Unauthorized Access";
            $data['reason'] = "Token Not Provided";
            return new JsonResponse($data, 401);
        }
    }
}

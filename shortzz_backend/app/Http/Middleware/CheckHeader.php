<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckHeader
{
    public function handle(Request $request, Closure $next)
    {
        if (isset($_SERVER['HTTP_APIKEY'])) {

            $apikey = $_SERVER['HTTP_APIKEY'];

            // Must match `apiKey` in the Flutter app (lib/utilities/const_res.dart)
            if ($apikey == 'silitibum') {
                return $next($request);
            } else {

                $data['status']    = false;
                $data['message']  = "Invalid API Key!";
                return new JsonResponse($data, 401);
            }
        } else {
            $data['status']    = false;
            $data['message']  = "Unauthorized Access!";
            return new JsonResponse($data, 401);
        }
    }
}

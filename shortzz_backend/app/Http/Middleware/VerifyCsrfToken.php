<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        // External payment/subscription webhooks — providers can't send a
        // Laravel CSRF token, they're authenticated by a shared secret
        // instead (checked inside each webhook controller).
        'webhooks/*',
    ];
}

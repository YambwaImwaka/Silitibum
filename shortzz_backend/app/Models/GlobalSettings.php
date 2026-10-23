<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class GlobalSettings extends Model
{
    use HasFactory;
    public $table = "tbl_settings";

    // SettingsController::fetchSettings serializes this whole row to any
    // caller holding the app's (hardcoded, therefore public) apikey — so
    // anything listed here would otherwise be world-readable. These are
    // server-side payment credentials: nothing in the mobile app reads them,
    // only PaymentGatewayFactory and PaymentWebhookController do, and they
    // use attribute access, which $hidden does not affect.
    //
    // Deliberately NOT hidden, though they are also secrets: sight_engine_*,
    // zego_app_sign, deepar_*, giphy_key, place_api_access_token. The app
    // calls those third parties directly from the client and breaks without
    // them (see sight_engine_service.dart). Moving those server-side is a
    // separate job — hiding them here would just take moderation down.
    //
    // place_api_access_token is exactly what its name says and NOTHING else:
    // a Google Places bearer read by common_service.dart's searchPlace/
    // searchNearBy. GlobalFunction::regenerateGoogleAPIToken() used to
    // overwrite it with a cloud-platform-scoped FCM token, which meant every
    // client was handed a credential good for most of the GCP project. The
    // FCM token now lives in the cache under
    // GlobalFunction::FCM_TOKEN_CACHE_KEY. Do not put a server credential in
    // this column again — everything here goes out over the wire.
    protected $hidden = [
        'dpo_company_token',
        'lenco_api_key',
        'lenco_account_id',
        'paystack_secret_key',
        'flutterwave_secret_key',
        // Internal watermarks for posts:rollup-engagement. Not secret, just
        // noise the app has no use for.
        'views_rollup_cursor',
        'shares_rollup_cursor',
    ];

    // fetchSettings() caches its payload under this key (SettingsController).
    // There are ~15 separate admin actions that save() this row (general
    // settings, privacy/terms, admob, coin rules, etc.) — busting the cache
    // here once, on the model, means none of them can forget to invalidate
    // it, instead of relying on every current and future save call site to
    // remember to do it individually.
    protected static function booted()
    {
        static::saved(function () {
            Cache::forget('app_settings_payload');
        });
    }
}

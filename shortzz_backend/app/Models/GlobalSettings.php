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
    protected $hidden = [
        'dpo_company_token',
        'lenco_api_key',
        'paystack_secret_key',
        'flutterwave_secret_key',
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

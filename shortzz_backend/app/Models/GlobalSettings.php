<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class GlobalSettings extends Model
{
    use HasFactory;
    public $table = "tbl_settings";

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

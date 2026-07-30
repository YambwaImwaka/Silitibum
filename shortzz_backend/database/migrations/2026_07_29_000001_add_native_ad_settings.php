<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Native in-feed ads for the Snaps feed. Banner/interstitial units already
// live on tbl_settings (admob_banner/admob_int/...); native ads need their own
// unit ids because AdMob treats them as a separate ad format, plus a frequency
// knob so the cadence can be tuned without an app release.
//
// Deliberately separate from admob_android_status/admob_ios_status rather than
// reusing them: an operator needs to be able to run banners/interstitials
// while native in-feed ads are still off (or vice versa). The client requires
// BOTH native_ad_status and the per-platform admob status to be on before it
// will request a native ad.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_settings', function (Blueprint $table) {
            $table->string('native_ad_android', 191)->nullable();
            $table->string('native_ad_ios', 191)->nullable();
            $table->unsignedTinyInteger('native_ad_status')->default(0);
            // Organic Snaps between consecutive ads. 8 is the shipping default;
            // the admin UI enforces a floor of 3 so the feed cannot be
            // configured into something that reads as ad-spam.
            $table->unsignedInteger('native_ad_frequency')->default(8);
        });
    }

    public function down(): void
    {
        Schema::table('tbl_settings', function (Blueprint $table) {
            $table->dropColumn([
                'native_ad_android',
                'native_ad_ios',
                'native_ad_status',
                'native_ad_frequency',
            ]);
        });
    }
};

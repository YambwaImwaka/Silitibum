<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Verified payout accounts (the Creator Program).
//
// A withdrawal used to name its destination number as free text on every
// request, so anyone holding a creator's session could send that creator's
// balance anywhere. Now a creator registers one mobile-money number with their
// legal name; where the payout provider can look the number up (Lenco
// /resolve/mobile-money returns the name the SIM is registered to), the two
// names must match. Changing the number starts a hold before any withdrawal.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('creator_profiles')) {
            Schema::create('creator_profiles', function (Blueprint $table) {
                $table->integer('user_id')->primary();
                $table->string('legal_name', 150);
                $table->string('payout_phone', 20);
                $table->string('payout_operator', 20);
                // The name the network has on file for payout_phone. Admin-only:
                // the app is told match / mismatch, never the name itself.
                $table->string('payout_account_name', 191)->nullable();
                // match | mismatch | unverified (no lookup available)
                $table->string('name_match', 12)->default('unverified');
                // An admin who checked a mismatch by hand and accepted it.
                $table->string('name_override_by', 100)->nullable();
                $table->timestamp('adult_confirmed_at')->nullable();
                $table->timestamp('payout_changed_at')->nullable();
                // active | suspended
                $table->string('status', 12)->default('active');
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        $settingColumns = [
            // The Lenco account payouts are sent FROM (a UUID). Secret-adjacent
            // and useless to the app: hidden in GlobalSettings.
            'lenco_account_id' => 'VARCHAR(64) NULL',
            'creator_min_account_age_days' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 30',
            'payout_change_hold_hours' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 72',
        ];
        foreach ($settingColumns as $column => $ddl) {
            if (!Schema::hasColumn('tbl_settings', $column)) {
                DB::statement("ALTER TABLE `tbl_settings` ADD COLUMN `{$column}` {$ddl}");
            }
        }
    }

    public function down(): void
    {
        foreach (['payout_change_hold_hours', 'creator_min_account_age_days', 'lenco_account_id'] as $column) {
            if (Schema::hasColumn('tbl_settings', $column)) {
                DB::statement("ALTER TABLE `tbl_settings` DROP COLUMN `{$column}`");
            }
        }
        Schema::dropIfExists('creator_profiles');
    }
};

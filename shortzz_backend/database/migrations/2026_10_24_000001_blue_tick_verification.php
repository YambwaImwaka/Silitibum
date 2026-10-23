<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Blue ticks from three sources, each able to end only its own kind
 * (App\Services\Verification\BlueTick):
 *  - admin: granted in the admin panel to anyone, never expires;
 *  - paid:  bought with mobile money on the web (Lenco), lasts
 *           verification_days;
 *  - store: a Play / App Store subscription (RevenueCat).
 * Without the source, a store subscription lapsing would have removed a tick
 * an admin gave.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('tbl_users', 'verification_source')) {
            DB::statement("ALTER TABLE `tbl_users` ADD COLUMN `verification_source` VARCHAR(20) NULL DEFAULT NULL AFTER `is_verify`");
        }
        if (!Schema::hasColumn('tbl_users', 'verified_until')) {
            DB::statement("ALTER TABLE `tbl_users` ADD COLUMN `verified_until` DATETIME NULL DEFAULT NULL AFTER `verification_source`");
            DB::statement("ALTER TABLE `tbl_users` ADD INDEX `tbl_users_verification_expiry_index` (`verification_source`, `verified_until`)");
        }
        // Every tick so far came from the admin panel (the store subscription
        // never had a product to sell).
        DB::table('tbl_users')->where('is_verify', 1)->whereNull('verification_source')
            ->update(['verification_source' => 'admin']);

        $settings = [
            'verification_enabled' => "TINYINT NOT NULL DEFAULT 1",
            // Kwacha for one period of the blue tick.
            'verification_price' => "DECIMAL(10,2) NOT NULL DEFAULT 50.00",
            // Length of a paid period; 0 means it never expires.
            'verification_days' => "INT NOT NULL DEFAULT 30",
        ];
        foreach ($settings as $column => $definition) {
            if (!Schema::hasColumn('tbl_settings', $column)) {
                DB::statement("ALTER TABLE `tbl_settings` ADD COLUMN `{$column}` {$definition}");
            }
        }

        // A mobile-money payment is either coins or a blue tick; both share the
        // charge, webhook and reconcile path of web top-ups.
        if (!Schema::hasColumn('tbl_coin_topup_request', 'purpose')) {
            DB::statement("ALTER TABLE `tbl_coin_topup_request` ADD COLUMN `purpose` VARCHAR(20) NOT NULL DEFAULT 'coins' AFTER `user_id`");
        }
        DB::statement("ALTER TABLE `tbl_coin_topup_request` MODIFY `coin_package_id` BIGINT UNSIGNED NULL DEFAULT NULL");
    }

    public function down(): void
    {
        if (Schema::hasColumn('tbl_users', 'verified_until')) {
            DB::statement("ALTER TABLE `tbl_users` DROP INDEX `tbl_users_verification_expiry_index`");
            DB::statement("ALTER TABLE `tbl_users` DROP COLUMN `verified_until`");
        }
        if (Schema::hasColumn('tbl_users', 'verification_source')) {
            DB::statement("ALTER TABLE `tbl_users` DROP COLUMN `verification_source`");
        }
        foreach (['verification_enabled', 'verification_price', 'verification_days'] as $column) {
            if (Schema::hasColumn('tbl_settings', $column)) {
                DB::statement("ALTER TABLE `tbl_settings` DROP COLUMN `{$column}`");
            }
        }
        if (Schema::hasColumn('tbl_coin_topup_request', 'purpose')) {
            DB::statement("ALTER TABLE `tbl_coin_topup_request` DROP COLUMN `purpose`");
        }
    }
};

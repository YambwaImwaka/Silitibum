<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Withdrawal references that can actually be sent to a payout provider.
//
// request_number doubles as the reference a provider sees, and Lenco rejects a
// reused reference ("Duplicate reference"). It was `userId-rand(1000..9999)`
// with its uniqueness checked against tbl_users.username, so collisions were a
// matter of time, and a retry re-sent the same reference and could never
// succeed. Now: a unique index on request_number, and every provider attempt
// sends `{request_number}-{attempt}` (stored in provider_reference before the
// call), with the provider's own transaction id kept apart in provider_txn_id.
//
// gateway was varchar(20) while the API accepts up to 100 characters, so a
// longer gateway title failed the insert outright under strict mode.
//
// Raw statements rather than Blueprint->change(): doctrine/dbal is not
// installed, and ->change() silently requires it on this Laravel version.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('tbl_redeem_request', 'attempt')) {
            DB::statement('ALTER TABLE `tbl_redeem_request` ADD COLUMN `attempt` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `provider_reference`');
        }
        if (!Schema::hasColumn('tbl_redeem_request', 'provider_txn_id')) {
            DB::statement('ALTER TABLE `tbl_redeem_request` ADD COLUMN `provider_txn_id` VARCHAR(191) NULL AFTER `attempt`');
        }
        DB::statement('ALTER TABLE `tbl_redeem_request` MODIFY `gateway` VARCHAR(100) NULL');

        // Rename every duplicate but the oldest so the unique index can land.
        $dupes = DB::table('tbl_redeem_request')
            ->select('request_number')
            ->whereNotNull('request_number')
            ->groupBy('request_number')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('request_number');
        foreach ($dupes as $number) {
            $ids = DB::table('tbl_redeem_request')->where('request_number', $number)->orderBy('id')->pluck('id');
            foreach ($ids->slice(1) as $id) {
                DB::table('tbl_redeem_request')->where('id', $id)
                    ->update(['request_number' => $number . '-D' . $id]);
            }
        }

        // Webhooks look a payout up by any of the three references; the app
        // lists a user's own requests newest-first.
        $indexes = [
            'uniq_redeem_request_number' => 'ADD UNIQUE KEY `uniq_redeem_request_number` (`request_number`)',
            'idx_redeem_provider_reference' => 'ADD INDEX `idx_redeem_provider_reference` (`provider_reference`)',
            'idx_redeem_provider_txn_id' => 'ADD INDEX `idx_redeem_provider_txn_id` (`provider_txn_id`)',
            'idx_redeem_user' => 'ADD INDEX `idx_redeem_user` (`user_id`, `id`)',
        ];
        foreach ($indexes as $name => $ddl) {
            if (!$this->hasIndex($name)) {
                DB::statement("ALTER TABLE `tbl_redeem_request` {$ddl}");
            }
        }
    }

    public function down(): void
    {
        foreach (['idx_redeem_user', 'idx_redeem_provider_txn_id', 'idx_redeem_provider_reference', 'uniq_redeem_request_number'] as $name) {
            if ($this->hasIndex($name)) {
                DB::statement("ALTER TABLE `tbl_redeem_request` DROP INDEX `{$name}`");
            }
        }
        if (Schema::hasColumn('tbl_redeem_request', 'provider_txn_id')) {
            DB::statement('ALTER TABLE `tbl_redeem_request` DROP COLUMN `provider_txn_id`');
        }
        if (Schema::hasColumn('tbl_redeem_request', 'attempt')) {
            DB::statement('ALTER TABLE `tbl_redeem_request` DROP COLUMN `attempt`');
        }
    }

    private function hasIndex(string $name): bool
    {
        return collect(DB::select('SHOW INDEX FROM `tbl_redeem_request` WHERE Key_name = ?', [$name]))->isNotEmpty();
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Closes the signup double-submit race: without a unique constraint, two
// concurrent registerUser requests for the same identity could both pass
// the "does this identity exist" check and both insert, leaving duplicate
// rows that made login non-deterministic (whichever row MySQL happened to
// return first). Pre-existing duplicates are never deleted — the row most
// likely to be the real, completed account (has a password, most recently
// updated) keeps the original identity; any others are renamed with a
// "+dupN" suffix so they stop colliding but stay in the table for manual
// review.
//
// `identity` is a mediumtext column, so MySQL requires a prefix length for
// the index — 191 chars is far beyond any real email/phone value.
return new class extends Migration
{
    public function up(): void
    {
        $duplicateIdentities = DB::table('tbl_users')
            ->select('identity')
            ->whereNotNull('identity')
            ->groupBy('identity')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('identity');

        foreach ($duplicateIdentities as $identity) {
            $rows = DB::table('tbl_users')
                ->where('identity', $identity)
                ->orderByRaw('password IS NULL') // rows with a password first
                ->orderByDesc('updated_at')
                ->get()
                ->values();

            foreach ($rows->slice(1) as $i => $row) {
                DB::table('tbl_users')
                    ->where('id', $row->id)
                    ->update(['identity' => $identity . '+dup' . ($i + 1) . '+' . $row->id]);
            }
        }

        DB::statement('ALTER TABLE `tbl_users` ADD UNIQUE KEY `uniq_users_identity` (`identity`(191))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `tbl_users` DROP INDEX `uniq_users_identity`');
    }
};

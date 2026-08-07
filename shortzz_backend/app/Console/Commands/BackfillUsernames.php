<?php

namespace App\Console\Commands;

use App\Models\Users;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/// One-off cleanup for handles produced by the old generateUsername(), which
/// appended rand(1, 9999) to every account unconditionally — so users show up
/// as "JaneDoe4821" even where "JaneDoe" was never taken.
///
/// This rewrites a user-visible identifier, so it is deliberately opt-in and
/// dry by default. It only touches accounts whose current username is exactly
/// "<cleaned fullname><digits>", which is the signature the old generator
/// left behind; anyone who has since chosen their own handle does not match
/// and is skipped.
class BackfillUsernames extends Command
{
    protected $signature = 'users:backfill-usernames
                            {--apply : Actually write the changes (otherwise dry-run)}
                            {--limit=0 : Stop after this many rewrites (0 = no limit)}';

    protected $description = 'Strip the auto-generated numeric suffix from usernames where the clean name is free';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $limit = (int) $this->option('limit');

        if (!$apply) {
            $this->warn('DRY RUN — no changes will be written. Re-run with --apply.');
        }

        $changed = 0;
        $skipped = 0;

        Users::select('id', 'username', 'fullname')
            ->orderBy('id')
            ->chunkById(200, function ($users) use ($apply, $limit, &$changed, &$skipped) {
                foreach ($users as $user) {
                    if ($limit > 0 && $changed >= $limit) {
                        return false;
                    }

                    $target = $this->cleanHandle($user->fullname);
                    if ($target === null) {
                        $skipped++;
                        continue;
                    }

                    // Only rewrite the exact old-generator shape: the clean
                    // name followed by nothing but digits. A handle the user
                    // picked themselves will not match.
                    if (!preg_match('/^' . preg_quote($target, '/') . '[0-9]+$/i', (string) $user->username)) {
                        $skipped++;
                        continue;
                    }

                    // The clean name must actually be free.
                    $taken = Users::where('username', $target)
                        ->where('id', '!=', $user->id)
                        ->exists();
                    if ($taken) {
                        $skipped++;
                        continue;
                    }

                    $this->line("  {$user->username}  ->  {$target}");

                    if ($apply) {
                        DB::table('tbl_users')
                            ->where('id', $user->id)
                            ->update(['username' => $target]);
                    }
                    $changed++;
                }

                return true;
            });

        $this->info(($apply ? 'Rewrote' : 'Would rewrite') . " {$changed} username(s); skipped {$skipped}.");

        return self::SUCCESS;
    }

    /// Mirrors GlobalFunction::generateUsername()'s cleaning rules so the
    /// match below reflects what the old generator would have produced.
    private function cleanHandle(?string $fullname): ?string
    {
        $base = preg_replace('/[^A-Za-z0-9._]/', '', (string) $fullname);
        $base = trim($base, '._');

        if (strlen($base) < 3) {
            return null;
        }

        return substr($base, 0, 25);
    }
}

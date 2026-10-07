<?php

namespace App\Console\Commands;

use App\Models\Admin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;

// Shell-only replacement for the removed web "Forget Password?" form
// (LoginController::forgotPasswordForm). Requiring SSH access to the server is
// the point: it is a far stronger proof of ownership than knowing the database
// password, which the web form accepted from anyone, without any rate limit.
//
// Stores the password the way LoginController/CheckLogin read it — reversible
// Crypt::encrypt, compared through decrypt() — not as a hash.
//
//   php artisan admin:reset-password
//   php artisan admin:reset-password --username=admin
class ResetAdminPassword extends Command
{
    protected $signature = 'admin:reset-password
                            {--username=admin : Admin account to reset}';

    protected $description = 'Set a new password for an admin-panel account (prompts for it; never takes it on the command line).';

    public function handle(): int
    {
        $username = (string) $this->option('username');
        $admin = Admin::where('admin_username', $username)->first();
        if ($admin === null) {
            $this->error("No admin account named \"$username\".");
            return self::FAILURE;
        }

        // secret(), not an argument or option, so the password never lands in
        // shell history or the process list.
        $password = (string) $this->secret('New password (at least 10 characters)');
        if (strlen($password) < 10) {
            $this->error('Password must be at least 10 characters.');
            return self::FAILURE;
        }
        if ($password !== (string) $this->secret('Confirm new password')) {
            $this->error('Passwords do not match.');
            return self::FAILURE;
        }

        $admin->admin_password = Crypt::encrypt($password);
        $admin->save();

        // CheckLogin re-compares the session's password with the stored one
        // on every request, so existing sessions end on their next click.
        $this->info("Password updated for \"$username\". Existing admin sessions are signed out on their next request.");
        return self::SUCCESS;
    }
}

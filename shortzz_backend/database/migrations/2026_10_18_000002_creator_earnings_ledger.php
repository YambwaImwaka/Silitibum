<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Coins -> Gifts -> Earnings.
//
// Coins used to be one fungible balance: whatever a fan bought, a sign-up bonus
// granted or a gift delivered could all be cashed out at the admin-set
// coin_value. With coin_value above what coins cost, that was a money printer,
// and nothing recorded where any of it went.
//
// Now:
// - coin_wallet is spend-only. coin_wallet_value is what those coins cost
//   (purchases add their price; bonus and admin grants add 0).
// - A gift spends coins at their average cost and credits the creator
//   creator_share_percent of that into earnings_balance (K) — the only balance
//   that can be withdrawn. The rest is the platform's commission.
// - wallet_transactions records every movement, so balances can be proven
//   (wallet:reconcile) and creators can see what each video earned.
//
// Raw statements for the legacy tables: doctrine/dbal is not installed, and
// Blueprint->change() silently requires it on this Laravel version.
return new class extends Migration
{
    public function up(): void
    {
        $userColumns = [
            'coin_wallet_value' => "DECIMAL(14,4) NOT NULL DEFAULT 0 AFTER `coin_purchased_lifetime`",
            'earnings_balance' => "DECIMAL(14,4) NOT NULL DEFAULT 0 AFTER `coin_wallet_value`",
            'earnings_lifetime' => "DECIMAL(14,4) NOT NULL DEFAULT 0 AFTER `earnings_balance`",
        ];
        foreach ($userColumns as $column => $ddl) {
            if (!Schema::hasColumn('tbl_users', $column)) {
                DB::statement("ALTER TABLE `tbl_users` ADD COLUMN `{$column}` {$ddl}");
            }
        }

        if (!Schema::hasTable('wallet_transactions')) {
            Schema::create('wallet_transactions', function (Blueprint $table) {
                $table->bigIncrements('id');
                // NULL = the platform's own account (commission rows).
                $table->integer('user_id')->nullable();
                // coins | earnings | platform
                $table->string('account', 10);
                $table->string('type', 40);
                // Signed deltas: coins on the coins account; K on every account
                // (the cost basis moving with coins, earnings, commission).
                $table->bigInteger('coins')->default(0);
                $table->decimal('amount', 14, 4)->default(0);
                $table->bigInteger('balance_after_coins')->nullable();
                $table->decimal('balance_after_amount', 14, 4)->nullable();
                // The rows one action writes (a gift writes three) share this.
                $table->char('group_id', 36)->nullable();
                $table->integer('counterparty_user_id')->nullable();
                $table->integer('gift_id')->nullable();
                $table->unsignedBigInteger('post_id')->nullable();
                $table->unsignedBigInteger('livestream_id')->nullable();
                $table->integer('redeem_request_id')->nullable();
                $table->unsignedBigInteger('topup_id')->nullable();
                // Exactly-once credits: a replayed purchase, webhook delivery
                // or migration run collides here and changes nothing.
                $table->string('idempotency_key', 191)->nullable()->unique('uniq_wallet_tx_idempotency');
                $table->text('meta')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['user_id', 'id'], 'idx_wallet_tx_user');
                $table->index(['user_id', 'account', 'created_at'], 'idx_wallet_tx_user_account_time');
                $table->index('post_id', 'idx_wallet_tx_post');
                $table->index(['type', 'created_at'], 'idx_wallet_tx_type_time');
                $table->index('group_id', 'idx_wallet_tx_group');
            });
        }

        $settingColumns = [
            'creator_share_percent' => 'TINYINT UNSIGNED NOT NULL DEFAULT 60',
            'min_withdrawal_amount' => 'DECIMAL(10,2) NOT NULL DEFAULT 50.00',
            'earnings_hold_days' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 7',
            'max_withdrawals_per_week' => 'TINYINT UNSIGNED NOT NULL DEFAULT 1',
            'currency_code' => "CHAR(3) NOT NULL DEFAULT 'ZMW'",
            // Lets the app ship the new wallet UI before the server flips it on.
            'wallet_v2' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
        ];
        foreach ($settingColumns as $column => $ddl) {
            if (!Schema::hasColumn('tbl_settings', $column)) {
                DB::statement("ALTER TABLE `tbl_settings` ADD COLUMN `{$column}` {$ddl}");
            }
        }

        // Withdrawals are now an amount in K taken from earnings. amount was a
        // varchar; anything non-numeric is zeroed first so the type change
        // cannot fail halfway.
        DB::statement("UPDATE `tbl_redeem_request` SET `amount` = '0' WHERE `amount` IS NULL OR TRIM(`amount`) = '' OR `amount` NOT REGEXP '^[0-9]+(\\\\.[0-9]+)?$'");
        DB::statement('ALTER TABLE `tbl_redeem_request` MODIFY `amount` DECIMAL(12,2) NOT NULL DEFAULT 0');
        $redeemColumns = [
            'operator' => 'VARCHAR(20) NULL AFTER `account`',
            'fee' => 'DECIMAL(10,2) NULL AFTER `amount`',
            // Shown to the creator when a payout is rejected or fails.
            'reason' => 'VARCHAR(255) NULL AFTER `status`',
            'processed_by' => 'VARCHAR(100) NULL AFTER `reason`',
        ];
        foreach ($redeemColumns as $column => $ddl) {
            if (!Schema::hasColumn('tbl_redeem_request', $column)) {
                DB::statement("ALTER TABLE `tbl_redeem_request` ADD COLUMN `{$column}` {$ddl}");
            }
        }
    }

    public function down(): void
    {
        foreach (['processed_by', 'reason', 'fee', 'operator'] as $column) {
            if (Schema::hasColumn('tbl_redeem_request', $column)) {
                DB::statement("ALTER TABLE `tbl_redeem_request` DROP COLUMN `{$column}`");
            }
        }
        DB::statement('ALTER TABLE `tbl_redeem_request` MODIFY `amount` VARCHAR(100) NOT NULL');

        foreach (['wallet_v2', 'currency_code', 'max_withdrawals_per_week', 'earnings_hold_days', 'min_withdrawal_amount', 'creator_share_percent'] as $column) {
            if (Schema::hasColumn('tbl_settings', $column)) {
                DB::statement("ALTER TABLE `tbl_settings` DROP COLUMN `{$column}`");
            }
        }

        Schema::dropIfExists('wallet_transactions');

        foreach (['earnings_lifetime', 'earnings_balance', 'coin_wallet_value'] as $column) {
            if (Schema::hasColumn('tbl_users', $column)) {
                DB::statement("ALTER TABLE `tbl_users` DROP COLUMN `{$column}`");
            }
        }
    }
};

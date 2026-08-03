<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Admin account deletion (App\Services\AccountDeleter) keeps coin top-up and
// redeem rows as a payment audit trail but strips the link to the deleted
// person. Both user_id columns were NOT NULL, so that de-identifying UPDATE
// failed outright — the choice was keep the money records fully identified or
// destroy the trail entirely. Making the column nullable is what allows the
// third option.
//
// Raw statements rather than Blueprint->change(): doctrine/dbal is not
// installed, and ->change() silently requires it on this Laravel version.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE `tbl_coin_topup_request` MODIFY `user_id` INT NULL');
        DB::statement('ALTER TABLE `tbl_redeem_request` MODIFY `user_id` INT NULL');
    }

    public function down(): void
    {
        // Rows belonging to already-deleted accounts cannot be restored to a
        // real user, so they would block a NOT NULL constraint. Park them on 0
        // rather than fail the rollback.
        DB::statement('UPDATE `tbl_coin_topup_request` SET `user_id` = 0 WHERE `user_id` IS NULL');
        DB::statement('UPDATE `tbl_redeem_request` SET `user_id` = 0 WHERE `user_id` IS NULL');
        DB::statement('ALTER TABLE `tbl_coin_topup_request` MODIFY `user_id` INT NOT NULL');
        DB::statement('ALTER TABLE `tbl_redeem_request` MODIFY `user_id` INT NOT NULL');
    }
};

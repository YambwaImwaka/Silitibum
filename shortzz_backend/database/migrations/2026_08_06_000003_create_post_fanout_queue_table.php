<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Work queue for "someone you follow posted" notifications.
 *
 * QUEUE_CONNECTION is sync on this host and there is no queue worker, so the
 * fan-out cannot happen inline: notifying an author's followers one by one
 * during their upload request would make posting take as long as they have
 * followers. Enqueueing is a single INSERT; posts:fanout then walks the
 * follower list in chunks across scheduler ticks, resuming from cursor_user_id.
 *
 * post_id is UNIQUE so the whole thing is idempotent — a retry, or a post
 * being published twice, can never double-notify.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('post_fanout_queue')) {
            Schema::create('post_fanout_queue', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('post_id');
                $table->unsignedBigInteger('cursor_user_id')->default(0);
                $table->unsignedInteger('total_sent')->default(0);
                // 0=pending 1=running 2=done 3=aborted (too many followers)
                $table->tinyInteger('status')->default(0);
                $table->tinyInteger('attempts')->default(0);
                $table->timestamps();

                $table->unique('post_id', 'uniq_fanout_post');
                $table->index(['status', 'id'], 'idx_fanout_status');
            });
        }

        if (Schema::hasTable('tbl_users') && !Schema::hasColumn('tbl_users', 'notify_new_post')) {
            DB::statement('ALTER TABLE `tbl_users` ADD COLUMN `notify_new_post` TINYINT NOT NULL DEFAULT 1');
        }

        // Safety valve: above this follower count we skip the per-follower DB
        // fan-out entirely rather than writing hundreds of thousands of
        // notification rows for one celebrity post.
        if (Schema::hasTable('tbl_settings') && !Schema::hasColumn('tbl_settings', 'fanout_max_followers')) {
            DB::statement('ALTER TABLE `tbl_settings` ADD COLUMN `fanout_max_followers` INT NOT NULL DEFAULT 20000');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('post_fanout_queue');

        if (Schema::hasTable('tbl_users') && Schema::hasColumn('tbl_users', 'notify_new_post')) {
            DB::statement('ALTER TABLE `tbl_users` DROP COLUMN `notify_new_post`');
        }
        if (Schema::hasTable('tbl_settings') && Schema::hasColumn('tbl_settings', 'fanout_max_followers')) {
            DB::statement('ALTER TABLE `tbl_settings` DROP COLUMN `fanout_max_followers`');
        }
    }
};

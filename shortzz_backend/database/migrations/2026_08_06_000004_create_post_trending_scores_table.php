<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Precomputed trending ranking.
 *
 * The scoring query joins four windowed aggregates over the engagement tables;
 * running that on every Explore open would be far too expensive on shared
 * hosting. posts:compute-trending runs it hourly and writes the result here,
 * so the read path is a single indexed join.
 *
 * rank_position is what the API paginates on. The usual keyset cursor
 * (`where id < last_item_id`) only works for id ordering and cannot express a
 * score order, while offset pagination would duplicate and skip rows every
 * time the hourly recompute reshuffles the list.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('post_trending_scores')) {
            return;
        }

        Schema::create('post_trending_scores', function (Blueprint $table) {
            $table->unsignedBigInteger('post_id')->primary();
            $table->decimal('score', 12, 4)->default(0);
            $table->unsignedInteger('rank_position')->default(0);
            $table->tinyInteger('post_type')->default(0);
            $table->timestamp('computed_at')->nullable();

            $table->index('rank_position', 'idx_pts_rank');
            $table->index(['post_type', 'rank_position'], 'idx_pts_type_rank');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_trending_scores');
    }
};

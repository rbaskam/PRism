<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pull_requests', function (Blueprint $table) {
            // Other ways the user is involved, worked out again by the next sync.
            $table->boolean('is_mentioned')->default(false);
            $table->boolean('has_my_comments')->default(false);
            $table->boolean('has_my_pending_review')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pull_requests', function (Blueprint $table) {
            $table->dropColumn(['is_mentioned', 'has_my_comments', 'has_my_pending_review']);
        });
    }
};

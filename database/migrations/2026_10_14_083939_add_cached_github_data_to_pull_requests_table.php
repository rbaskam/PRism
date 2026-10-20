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
            $table->longText('conversation')->nullable();
            $table->timestamp('conversation_synced_at')->nullable();
            $table->longText('files_snapshot')->nullable();
            $table->string('files_head_sha')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pull_requests', function (Blueprint $table) {
            $table->dropColumn(['conversation', 'conversation_synced_at', 'files_snapshot', 'files_head_sha']);
        });
    }
};

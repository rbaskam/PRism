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
        Schema::table('github_accounts', function (Blueprint $table) {
            // The URLs of open pull requests that mention the user, and of ones they commented on, from GitHub's search.
            $table->json('mentioned_in')->nullable();
            $table->json('commented_in')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('github_accounts', function (Blueprint $table) {
            $table->dropColumn(['mentioned_in', 'commented_in']);
        });
    }
};

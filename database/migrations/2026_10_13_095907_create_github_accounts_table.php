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
        Schema::create('github_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('github_id')->unique();
            $table->string('login');
            $table->string('name')->nullable();
            $table->string('avatar_url')->nullable();
            $table->text('token');
            $table->string('token_source');
            $table->json('scopes')->nullable();
            $table->json('teams')->nullable();
            $table->timestamp('teams_synced_at')->nullable();
            $table->string('notifications_last_modified')->nullable();
            $table->timestamp('notifications_polled_at')->nullable();
            $table->json('untracked_review_requests')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('github_accounts');
    }
};

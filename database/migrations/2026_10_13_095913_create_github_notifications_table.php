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
        Schema::create('github_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('github_id')->unique();
            $table->foreignId('pull_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason')->index();
            $table->string('subject_type');
            $table->string('subject_title');
            $table->string('subject_url')->nullable();
            $table->string('html_url')->nullable();
            $table->string('repository_full_name');
            $table->boolean('is_unread')->default(true)->index();
            $table->timestamp('github_updated_at')->nullable();
            $table->timestamp('desktop_notified_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('github_notifications');
    }
};

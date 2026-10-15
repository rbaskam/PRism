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
        Schema::create('pull_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->string('node_id')->unique();
            $table->unsignedInteger('number');
            $table->string('title');
            $table->longText('body_html')->nullable();
            $table->string('html_url');
            $table->string('state')->default('open')->index();
            $table->boolean('is_draft')->default(false);
            $table->string('author_login')->nullable();
            $table->string('author_avatar_url')->nullable();
            $table->string('head_ref')->nullable();
            $table->string('base_ref')->nullable();
            $table->string('head_sha')->nullable();
            $table->unsignedInteger('additions')->default(0);
            $table->unsignedInteger('deletions')->default(0);
            $table->unsignedInteger('changed_files')->default(0);
            $table->unsignedInteger('comments_count')->default(0);
            $table->string('review_decision')->nullable();
            $table->string('checks_state')->nullable();
            $table->string('mergeable')->nullable();
            $table->string('my_review_state')->nullable();
            $table->timestamp('my_review_submitted_at')->nullable();
            $table->string('my_review_commit_sha')->nullable();
            $table->boolean('is_review_requested_from_me')->default(false);
            $table->boolean('is_review_requested_directly')->default(false);
            $table->json('requested_via_teams')->nullable();
            $table->json('requested_reviewers')->nullable();
            $table->json('labels')->nullable();
            $table->boolean('is_authored_by_me')->default(false);
            $table->boolean('is_assigned_to_me')->default(false);
            $table->timestamp('github_created_at')->nullable();
            $table->timestamp('github_updated_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['repository_id', 'number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pull_requests');
    }
};

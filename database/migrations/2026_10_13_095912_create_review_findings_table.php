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
        Schema::create('review_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pull_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('review_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('last_seen_review_run_id')->nullable()->constrained('review_runs')->nullOnDelete();
            $table->string('fingerprint');
            $table->string('category');
            $table->string('severity');
            $table->string('path')->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->string('title');
            $table->text('body');
            $table->text('suggestion')->nullable();
            $table->string('status')->default('new')->index();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('github_comment_id')->nullable();
            $table->string('github_comment_url')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['pull_request_id', 'fingerprint']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('review_findings');
    }
};

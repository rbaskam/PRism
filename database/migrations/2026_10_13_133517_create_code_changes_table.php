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
        Schema::create('code_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pull_request_id')->constrained()->cascadeOnDelete();
            $table->string('status')->index();
            $table->string('target');
            $table->text('instructions')->nullable();
            $table->json('finding_ids')->nullable();
            $table->json('comments')->nullable();
            $table->string('checkout_path');
            $table->string('worktree_path')->nullable();
            $table->string('branch')->nullable();
            $table->string('base_sha')->nullable();
            $table->string('head_sha')->nullable();
            $table->text('summary')->nullable();
            $table->json('commits')->nullable();
            $table->longText('diff')->nullable();
            $table->unsignedInteger('files_changed')->default(0);
            $table->unsignedInteger('additions')->default(0);
            $table->unsignedInteger('deletions')->default(0);
            $table->text('error')->nullable();
            $table->string('model')->nullable();
            $table->decimal('cost_usd', 10, 4)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('pushed_branch')->nullable();
            $table->string('created_pull_request_url')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('pushed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('code_changes');
    }
};

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
        Schema::create('review_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pull_request_id')->constrained()->cascadeOnDelete();
            $table->string('status')->index();
            $table->string('head_sha')->nullable();
            $table->string('model')->nullable();
            $table->longText('prompt');
            $table->text('summary')->nullable();
            $table->string('verdict')->nullable();
            $table->unsignedInteger('new_findings_count')->default(0);
            $table->unsignedInteger('repeated_findings_count')->default(0);
            $table->unsignedInteger('resolved_findings_count')->default(0);
            $table->longText('raw_output')->nullable();
            $table->text('error')->nullable();
            $table->decimal('cost_usd', 10, 4)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('session_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('review_runs');
    }
};

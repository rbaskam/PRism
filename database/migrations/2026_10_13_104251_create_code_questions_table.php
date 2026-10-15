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
        Schema::create('code_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pull_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('code_questions')->cascadeOnDelete();
            $table->string('path');
            $table->string('side', 5)->default('RIGHT');
            $table->unsignedInteger('start_line');
            $table->unsignedInteger('end_line');
            $table->text('selected_code');
            $table->text('prompt');
            $table->string('status')->index();
            $table->longText('answer')->nullable();
            $table->text('suggested_code')->nullable();
            $table->text('error')->nullable();
            $table->string('head_sha')->nullable();
            $table->string('model')->nullable();
            $table->decimal('cost_usd', 10, 4)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('code_questions');
    }
};

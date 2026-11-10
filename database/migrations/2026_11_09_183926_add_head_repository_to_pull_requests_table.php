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
            // Null until the next sync, when PRism doesn't know yet whether the pull request comes from a fork.
            $table->boolean('is_cross_repository')->nullable();
            $table->string('head_repository')->nullable();
            $table->boolean('maintainer_can_modify')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pull_requests', function (Blueprint $table) {
            $table->dropColumn(['is_cross_repository', 'head_repository', 'maintainer_can_modify']);
        });
    }
};

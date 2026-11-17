<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('research_outputs', function (Blueprint $table) {
            $table->foreignUuid('research_run_id')->nullable()->unique()->constrained('research_runs');
            $table->json('provenance')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('research_outputs', function (Blueprint $table) {
            $table->dropForeign(['research_run_id']);
            $table->dropUnique(['research_run_id']);
            $table->dropColumn(['research_run_id', 'provenance']);
        });
    }
};

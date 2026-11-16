<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('research_sessions', function (Blueprint $table) {
            $table->text('thesis')->default('');
            $table->unsignedInteger('thesis_version')->default(1);
        });
        Schema::table('research_runs', fn (Blueprint $table) => $table->json('workspace')->nullable());
        Schema::create('workspace_proposals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('run_id')->constrained('research_runs');
            $table->foreignUuid('session_id')->constrained('research_sessions');
            $table->foreignId('user_id')->constrained();
            $table->unsignedBigInteger('remote_event_id');
            $table->string('operation');
            $table->json('payload');
            $table->string('status')->default('pending');
            $table->timestamps();
            $table->unique(['run_id', 'remote_event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_proposals');
        Schema::table('research_runs', fn (Blueprint $table) => $table->dropColumn('workspace'));
        Schema::table('research_sessions', fn (Blueprint $table) => $table->dropColumn(['thesis', 'thesis_version']));
    }
};

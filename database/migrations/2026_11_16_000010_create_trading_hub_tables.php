<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('research_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained();
            $table->string('title');
            $table->boolean('pinned')->default(false);
            $table->timestamps();
        });
        Schema::create('research_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('research_session_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->string('idempotency_key', 100);
            $table->text('prompt');
            $table->string('status')->default('queued')->index();
            $table->text('error')->nullable();
            $table->unsignedBigInteger('event_cursor')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key']);
        });
        Schema::create('research_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('research_session_id')->constrained();
            $table->foreignUuid('research_run_id')->constrained();
            $table->string('role');
            $table->longText('content');
            $table->timestamps();
            $table->unique(['research_run_id', 'role']);
        });
        Schema::create('research_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('research_run_id')->constrained();
            $table->unsignedBigInteger('remote_id');
            $table->string('type');
            $table->json('payload');
            $table->timestamps();
            $table->unique(['research_run_id', 'remote_id']);
        });
        Schema::create('board_cards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('research_session_id')->constrained();
            $table->string('title');
            $table->text('content')->default('');
            $table->string('status')->default('backlog');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('research_outputs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained();
            $table->foreignUuid('research_session_id')->nullable()->constrained();
            $table->string('external_id')->nullable()->unique();
            $table->string('title');
            $table->string('kind');
            $table->string('status')->default('draft')->index();
            $table->longText('content');
            $table->json('sources')->nullable();
            $table->string('original_checksum', 64);
            $table->unsignedInteger('version')->default(1);
            $table->text('review_comment')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
        Schema::create('output_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('research_output_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->timestamps();
            $table->unique(['research_output_id', 'version']);
        });
        Schema::create('community_topics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained();
            $table->string('title');
            $table->text('body');
            $table->boolean('locked')->default(false);
            $table->timestamps();
        });
        Schema::create('community_comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('community_topic_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->text('body');
            $table->timestamps();
        });
        Schema::create('integration_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('kind')->unique();
            $table->json('payload');
            $table->timestamp('observed_at');
            $table->timestamps();
        });
        Schema::create('audit_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->string('action');
            $table->string('subject_id');
            $table->json('metadata');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['audit_entries', 'integration_snapshots', 'community_comments', 'community_topics', 'output_revisions', 'research_outputs', 'board_cards', 'research_events', 'research_messages', 'research_runs', 'research_sessions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

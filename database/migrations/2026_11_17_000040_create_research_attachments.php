<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('research_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('research_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('media_type');
            $table->unsignedBigInteger('size');
            $table->string('sha256', 64);
            $table->string('storage_key');
            $table->longText('text');
            $table->timestamps();
        });
        Schema::table('research_runs', fn (Blueprint $table) => $table->json('attachments')->nullable());
    }

    public function down(): void
    {
        Schema::table('research_runs', fn (Blueprint $table) => $table->dropColumn('attachments'));
        Schema::dropIfExists('research_attachments');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('research_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('last_seen_event_id')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('research_sessions', function (Blueprint $table) {
            $table->dropColumn('last_seen_event_id');
        });
    }
};

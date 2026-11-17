<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('community_topics', function (Blueprint $table) {
            $table->foreignUuid('output_id')->nullable()->constrained('research_outputs');
            $table->unsignedInteger('output_version')->nullable();
            $table->string('output_title')->nullable();
            $table->string('output_kind')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('community_topics', function (Blueprint $table) {
            $table->dropForeign(['output_id']);
            $table->dropColumn(['output_id', 'output_version', 'output_title', 'output_kind']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_import_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('source_id', 100);
            $table->string('record_type', 20);
            $table->string('legacy_id', 100);
            $table->string('checksum', 64);
            $table->string('manifest_checksum', 64);
            $table->string('target_type', 100)->nullable();
            $table->string('target_id', 100)->nullable();
            $table->json('archive_payload')->nullable();
            $table->foreignId('mapped_user_id')->nullable()->constrained('users');
            $table->foreignId('imported_by')->constrained('users');
            $table->timestamps();
            $table->unique(['source_id', 'record_type', 'legacy_id'], 'legacy_import_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_import_receipts');
    }
};

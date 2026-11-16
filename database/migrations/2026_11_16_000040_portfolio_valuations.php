<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_valuations', function (Blueprint $table) {
            $table->id();
            $table->string('account', 100);
            $table->timestampTz('bucket_at');
            $table->timestampTz('observed_at', 6);
            $table->timestampTz('market_asof', 6)->nullable();
            $table->decimal('equity', 32, 8);
            $table->decimal('cash', 32, 8)->nullable();
            $table->decimal('deposits', 32, 8)->nullable();
            $table->string('source');
            $table->string('digest', 64);
            $table->json('provenance');
            $table->timestamps();
            $table->unique(['account', 'bucket_at']);
            $table->index(['account', 'observed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_valuations');
    }
};

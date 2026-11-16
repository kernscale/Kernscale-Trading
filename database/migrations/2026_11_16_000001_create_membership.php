<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->string('membership_status')->default('pending')->index());
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            $table->string('token_hash', 64)->unique();
            $table->string('role');
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });
        foreach (['owner', 'researcher', 'member'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('membership_status'));
    }
};

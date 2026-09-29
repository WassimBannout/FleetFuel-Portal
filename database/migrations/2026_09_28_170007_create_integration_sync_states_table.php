<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Operational metadata about external syncs (e.g. rates:sync), not
        // financial history. The error code is a safe short code, never a
        // raw provider response.
        Schema::create('integration_sync_states', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->dateTime('last_attempt_at')->nullable();
            $table->dateTime('last_success_at')->nullable();
            $table->string('last_error_code', 60)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_sync_states');
    }
};

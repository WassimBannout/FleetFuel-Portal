<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Earliest time the next sync should call the provider: its announced
        // next update after a success, or its retry advice after a 429.
        Schema::table('integration_sync_states', function (Blueprint $table) {
            $table->dateTime('next_attempt_at')->nullable()->after('last_error_code');
        });
    }

    public function down(): void
    {
        Schema::table('integration_sync_states', function (Blueprint $table) {
            $table->dropColumn('next_attempt_at');
        });
    }
};

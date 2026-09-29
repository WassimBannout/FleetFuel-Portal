<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 30)->after('password');
            $table->foreignId('company_id')->nullable()->after('role')->constrained()->restrictOnDelete();
            $table->foreignId('station_id')->nullable()->after('company_id')->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(true)->after('station_id');
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('admin', 'company_manager', 'station_operator'))");

        // Managers belong to exactly one company, operators to exactly one
        // station, admins to neither. The database rejects any other shape.
        DB::statement(<<<'SQL'
            ALTER TABLE users ADD CONSTRAINT users_role_scope_check CHECK (
                (role = 'admin' AND company_id IS NULL AND station_id IS NULL)
                OR (role = 'company_manager' AND company_id IS NOT NULL AND station_id IS NULL)
                OR (role = 'station_operator' AND station_id IS NOT NULL AND company_id IS NULL)
            )
            SQL);
    }

    public function down(): void
    {
        // Multi-column checks must go before the columns they reference.
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_role_scope_check');
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_role_check');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('station_id');
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn(['role', 'is_active']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            // MySQL allows many NULLs in a unique index, so the tax number is
            // unique only when provided.
            $table->string('tax_no', 50)->nullable()->unique();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        DB::statement("ALTER TABLE companies ADD CONSTRAINT companies_status_check CHECK (status IN ('active', 'inactive'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};

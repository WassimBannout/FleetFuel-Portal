<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id');
            $table->string('name', 120);
            $table->string('phone', 30)->nullable();
            $table->string('license_no', 50);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'license_no']);
            // Target for composite foreign keys (see vehicles).
            $table->unique(['company_id', 'id']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drivers');
    }
};

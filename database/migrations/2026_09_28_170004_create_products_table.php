<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 80);
            $table->string('fuel_type', 20);
            $table->string('unit', 10)->default('L');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::statement("ALTER TABLE products ADD CONSTRAINT products_fuel_type_check CHECK (fuel_type IN ('petrol', 'diesel'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};

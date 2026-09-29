<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id');
            $table->string('plate_no', 30)->unique();
            $table->string('fuel_type', 20);
            $table->decimal('tank_capacity_l', 10, 2);
            $table->bigInteger('odometer_km')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Target for composite foreign keys such as
            // fuel_cards (company_id, vehicle_id): a card can only point at a
            // vehicle of its own company.
            $table->unique(['company_id', 'id']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE vehicles ADD CONSTRAINT vehicles_fuel_type_check CHECK (fuel_type IN ('petrol', 'diesel'))");
        DB::statement('ALTER TABLE vehicles ADD CONSTRAINT vehicles_tank_capacity_positive_check CHECK (tank_capacity_l > 0)');
        DB::statement('ALTER TABLE vehicles ADD CONSTRAINT vehicles_odometer_nonnegative_check CHECK (odometer_km >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};

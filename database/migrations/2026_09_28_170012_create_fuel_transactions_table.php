<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only ledger of accepted purchases. Every row stores the
        // ownership, price and exchange-rate values that applied at the time,
        // so reports never depend on later edits to master data or prices.
        Schema::create('fuel_transactions', function (Blueprint $table) {
            $table->id();

            // Identity and idempotency
            $table->foreignId('fuel_card_id');
            $table->foreignId('station_id')->constrained()->restrictOnDelete();
            $table->string('external_ref', 100);
            $table->char('request_hash', 64);

            // Ownership snapshots
            $table->foreignId('company_id');
            $table->foreignId('vehicle_id')->nullable();
            $table->foreignId('driver_id')->nullable();
            $table->decimal('tank_capacity_l', 10, 2)->nullable();

            // Fuel
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('liters', 10, 2);
            $table->bigInteger('odometer_km')->nullable();

            // Price snapshot (LBP per liter)
            $table->foreignId('product_price_id')->constrained()->restrictOnDelete();
            $table->decimal('unit_price_lbp', 18, 4);
            $table->decimal('amount_lbp', 20, 2);

            // Exchange-rate snapshot
            $table->foreignId('exchange_rate_id')->constrained()->restrictOnDelete();
            $table->decimal('rate_lbp_per_usd', 20, 8);
            $table->string('rate_source', 20);
            $table->dateTime('rate_effective_at');
            $table->decimal('amount_usd', 18, 2);

            // Timing: event time, Beirut quota month and received time
            $table->dateTime('transacted_at');
            $table->date('quota_month');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            // The final arbiter for concurrent retries of the same POS request.
            $table->unique(['station_id', 'external_ref']);
            $table->index(['fuel_card_id', 'transacted_at', 'id']);
            $table->index(['company_id', 'transacted_at', 'id']);
            $table->index(['station_id', 'transacted_at', 'id']);
            $table->index(['vehicle_id', 'transacted_at', 'id']);

            // The company snapshot must match the card, vehicle and driver.
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'fuel_card_id'])->references(['company_id', 'id'])->on('fuel_cards')->restrictOnDelete();
            $table->foreign(['company_id', 'vehicle_id'])->references(['company_id', 'id'])->on('vehicles')->restrictOnDelete();
            $table->foreign(['company_id', 'driver_id'])->references(['company_id', 'id'])->on('drivers')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE fuel_transactions ADD CONSTRAINT fuel_transactions_liters_positive_check CHECK (liters > 0)');
        DB::statement('ALTER TABLE fuel_transactions ADD CONSTRAINT fuel_transactions_unit_price_positive_check CHECK (unit_price_lbp > 0)');
        DB::statement('ALTER TABLE fuel_transactions ADD CONSTRAINT fuel_transactions_amount_lbp_check CHECK (amount_lbp >= 0)');
        DB::statement('ALTER TABLE fuel_transactions ADD CONSTRAINT fuel_transactions_amount_usd_check CHECK (amount_usd >= 0)');
        DB::statement('ALTER TABLE fuel_transactions ADD CONSTRAINT fuel_transactions_rate_positive_check CHECK (rate_lbp_per_usd > 0)');
        DB::statement("ALTER TABLE fuel_transactions ADD CONSTRAINT fuel_transactions_rate_source_check CHECK (rate_source IN ('provider', 'manual', 'fixture'))");
        DB::statement('ALTER TABLE fuel_transactions ADD CONSTRAINT fuel_transactions_tank_positive_check CHECK (tank_capacity_l > 0)');
        DB::statement('ALTER TABLE fuel_transactions ADD CONSTRAINT fuel_transactions_odometer_check CHECK (odometer_km >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('fuel_transactions');
    }
};

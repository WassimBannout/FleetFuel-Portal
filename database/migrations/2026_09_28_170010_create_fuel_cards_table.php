<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fuel_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('vehicle_id')->nullable();
            $table->foreignId('driver_id')->nullable();
            $table->string('card_no', 40)->unique();
            $table->foreignId('allowed_product_id')->nullable()->constrained('products')->restrictOnDelete();
            // Null means unlimited; zero means no further spend.
            $table->decimal('monthly_limit_l', 12, 2)->nullable();
            $table->decimal('monthly_limit_usd', 18, 2)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            // Target for fuel_transactions (company_id, fuel_card_id).
            $table->unique(['company_id', 'id']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();

            // Same-company assignment enforced by the database. When
            // vehicle_id or driver_id is NULL the composite key is not checked.
            $table->foreign(['company_id', 'vehicle_id'])->references(['company_id', 'id'])->on('vehicles')->restrictOnDelete();
            $table->foreign(['company_id', 'driver_id'])->references(['company_id', 'id'])->on('drivers')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE fuel_cards ADD CONSTRAINT fuel_cards_status_check CHECK (status IN ('active', 'blocked', 'archived'))");
        DB::statement('ALTER TABLE fuel_cards ADD CONSTRAINT fuel_cards_limit_l_nonnegative_check CHECK (monthly_limit_l >= 0)');
        DB::statement('ALTER TABLE fuel_cards ADD CONSTRAINT fuel_cards_limit_usd_nonnegative_check CHECK (monthly_limit_usd >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('fuel_cards');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only price timeline in LBP per liter. A correction is a new
        // row with a new effective_from; rows are never updated or deleted.
        Schema::create('product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('price_lbp', 18, 4);
            $table->dateTime('effective_from');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            // Also serves "latest price at or before time T" lookups.
            $table->unique(['product_id', 'effective_from']);
        });

        DB::statement('ALTER TABLE product_prices ADD CONSTRAINT product_prices_price_positive_check CHECK (price_lbp > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_prices');
    }
};

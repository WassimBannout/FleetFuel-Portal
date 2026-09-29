<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Immutable timeline; the first row of every order is null -> pending.
        Schema::create('delivery_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_order_id')->constrained()->restrictOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->string('note', 255)->nullable();
            $table->dateTime('changed_at');

            $table->index(['delivery_order_id', 'changed_at', 'id']);
        });

        $statuses = "('pending', 'scheduled', 'out_for_delivery', 'delivered', 'cancelled')";
        DB::statement("ALTER TABLE delivery_status_history ADD CONSTRAINT delivery_status_history_to_check CHECK (to_status IN {$statuses})");
        DB::statement("ALTER TABLE delivery_status_history ADD CONSTRAINT delivery_status_history_from_check CHECK (from_status IN {$statuses})");
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_status_history');
    }
};

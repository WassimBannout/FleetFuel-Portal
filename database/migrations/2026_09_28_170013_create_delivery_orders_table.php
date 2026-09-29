<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('address', 500);
            $table->string('governorate', 80);
            $table->decimal('liters', 10, 2);
            $table->dateTime('preferred_start_at');
            $table->dateTime('preferred_end_at');
            $table->dateTime('scheduled_start_at')->nullable();
            $table->dateTime('scheduled_end_at')->nullable();
            $table->string('status', 30)->default('pending');
            $table->string('assigned_truck', 60)->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'created_at']);
            $table->index(['status', 'preferred_start_at']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE delivery_orders ADD CONSTRAINT delivery_orders_liters_positive_check CHECK (liters > 0)');
        DB::statement("ALTER TABLE delivery_orders ADD CONSTRAINT delivery_orders_status_check CHECK (status IN ('pending', 'scheduled', 'out_for_delivery', 'delivered', 'cancelled'))");
        DB::statement('ALTER TABLE delivery_orders ADD CONSTRAINT delivery_orders_preferred_window_check CHECK (preferred_end_at > preferred_start_at)');
        DB::statement('ALTER TABLE delivery_orders ADD CONSTRAINT delivery_orders_scheduled_window_check CHECK (scheduled_end_at > scheduled_start_at)');

        // Each state carries the details it needs, whichever code wrote it.
        DB::statement(<<<'SQL'
            ALTER TABLE delivery_orders ADD CONSTRAINT delivery_orders_schedule_details_check CHECK (
                status NOT IN ('scheduled', 'out_for_delivery', 'delivered')
                OR (scheduled_start_at IS NOT NULL AND scheduled_end_at IS NOT NULL AND assigned_truck IS NOT NULL)
            )
            SQL);
        DB::statement("ALTER TABLE delivery_orders ADD CONSTRAINT delivery_orders_delivered_at_check CHECK (status <> 'delivered' OR delivered_at IS NOT NULL)");
        DB::statement("ALTER TABLE delivery_orders ADD CONSTRAINT delivery_orders_cancel_reason_check CHECK (status <> 'cancelled' OR cancel_reason IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_orders');
    }
};

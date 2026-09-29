<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Running monthly totals per card. Updated only in the same database
        // transaction that inserts an accepted fuel transaction, while holding
        // the card row lock. month_start is the first Beirut calendar day.
        Schema::create('card_monthly_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fuel_card_id')->constrained()->restrictOnDelete();
            $table->date('month_start');
            $table->decimal('used_l', 14, 2)->default(0);
            $table->decimal('used_usd', 20, 2)->default(0);
            $table->timestamps();

            $table->unique(['fuel_card_id', 'month_start']);
        });

        DB::statement('ALTER TABLE card_monthly_usage ADD CONSTRAINT card_monthly_usage_used_l_check CHECK (used_l >= 0)');
        DB::statement('ALTER TABLE card_monthly_usage ADD CONSTRAINT card_monthly_usage_used_usd_check CHECK (used_usd >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('card_monthly_usage');
    }
};

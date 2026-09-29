<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Immutable USD/LBP observations. A rate is eligible at time T when
        // effective_at <= T < expires_at (docs/04-BUSINESS-RULES.md).
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->char('base', 3);
            $table->char('quote', 3);
            $table->decimal('rate', 20, 8);
            $table->string('source', 20);
            $table->dateTime('effective_at');
            $table->dateTime('fetched_at')->nullable();
            $table->dateTime('expires_at');
            // Null for automated observations; required for manual overrides.
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reason', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['base', 'quote', 'source', 'effective_at']);
            $table->index(['base', 'quote', 'effective_at']);
        });

        DB::statement('ALTER TABLE exchange_rates ADD CONSTRAINT exchange_rates_rate_positive_check CHECK (rate > 0)');
        DB::statement("ALTER TABLE exchange_rates ADD CONSTRAINT exchange_rates_source_check CHECK (source IN ('provider', 'manual', 'fixture'))");
        DB::statement('ALTER TABLE exchange_rates ADD CONSTRAINT exchange_rates_expiry_check CHECK (expires_at > effective_at)');
        DB::statement('ALTER TABLE exchange_rates ADD CONSTRAINT exchange_rates_pair_check CHECK (base <> quote)');
        DB::statement(<<<'SQL'
            ALTER TABLE exchange_rates ADD CONSTRAINT exchange_rates_manual_reason_check CHECK (
                source <> 'manual' OR (reason IS NOT NULL AND created_by IS NOT NULL)
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};

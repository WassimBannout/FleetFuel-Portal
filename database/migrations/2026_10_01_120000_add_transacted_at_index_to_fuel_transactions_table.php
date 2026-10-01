<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reports and the accounting CSV across all companies filter the ledger by
 * time alone. Without an index that starts with transacted_at, each such
 * query, and each chunk of a CSV export, read the whole table
 * (docs/REPORT-QUERY-PLANS.md). The tenant-scoped indexes stay as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fuel_transactions', function (Blueprint $table) {
            $table->index(['transacted_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('fuel_transactions', function (Blueprint $table) {
            $table->dropIndex(['transacted_at', 'id']);
        });
    }
};

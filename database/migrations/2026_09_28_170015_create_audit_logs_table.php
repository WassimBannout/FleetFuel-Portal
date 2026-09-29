<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only record of sensitive changes. Values hold explicitly
        // selected business fields only, never passwords, tokens or requests.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('action', 80);
            // A stable alias from the morph map (e.g. "fuel_card"), not a class name.
            $table->string('auditable_type', 80);
            $table->unsignedBigInteger('auditable_id');
            $table->foreignId('company_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['auditable_type', 'auditable_id', 'created_at']);
            $table->index(['company_id', 'created_at']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};

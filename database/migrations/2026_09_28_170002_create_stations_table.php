<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('district', 80);
            $table->string('governorate', 80);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE stations ADD CONSTRAINT stations_latitude_check CHECK (latitude BETWEEN -90 AND 90)');
        DB::statement('ALTER TABLE stations ADD CONSTRAINT stations_longitude_check CHECK (longitude BETWEEN -180 AND 180)');
    }

    public function down(): void
    {
        Schema::dropIfExists('stations');
    }
};

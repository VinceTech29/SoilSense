<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sensor_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('soil_sample_id')->nullable()->constrained('soil_samples')->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('iot_devices')->nullOnDelete();

            // Agricultural sensor measurements (Nullable - never convert missing data to 0)
            $table->decimal('ph', 4, 2)->nullable()->comment('Soil pH: 0.00 to 14.00');
            $table->decimal('moisture', 6, 2)->nullable()->comment('Raw index or calibrated % VWC');
            $table->string('moisture_unit', 30)->nullable()->default('raw index');
            $table->decimal('temperature', 5, 2)->nullable()->comment('Soil temperature in Celsius');
            $table->decimal('ec', 7, 2)->nullable()->comment('Electrical conductivity');
            $table->string('ec_unit', 20)->nullable()->default('mS/cm');

            // Metadata and validity
            $table->boolean('is_valid')->default(true)->comment('Flagged false if sensor is disconnected or out of physical bounds');
            $table->string('validity_notes')->nullable();
            $table->json('calibration_metadata')->nullable()->comment('Details on probe offset or calibration state');
            $table->dateTime('recorded_at');
            $table->string('data_source', 20)->default('demo')->comment('live, manual, demo');
            $table->timestamps();

            $table->index(['soil_sample_id', 'recorded_at']);
            $table->index(['device_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sensor_readings');
    }
};

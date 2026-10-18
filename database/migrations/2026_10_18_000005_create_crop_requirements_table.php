<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crop_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crop_profile_id')->constrained('crop_profiles')->cascadeOnDelete();

            // Documented Soil pH ranges
            $table->decimal('min_ph', 4, 2)->comment('Tolerable lower bound pH');
            $table->decimal('max_ph', 4, 2)->comment('Tolerable upper bound pH');
            $table->decimal('optimal_ph_min', 4, 2)->nullable()->comment('Optimal lower bound pH');
            $table->decimal('optimal_ph_max', 4, 2)->nullable()->comment('Optimal upper bound pH');

            // Moisture requirements
            $table->decimal('min_moisture', 6, 2)->nullable();
            $table->decimal('max_moisture', 6, 2)->nullable();
            $table->decimal('optimal_moisture_min', 6, 2)->nullable();
            $table->decimal('optimal_moisture_max', 6, 2)->nullable();
            $table->string('moisture_unit', 30)->default('% VWC');

            // Temperature requirements in Celsius
            $table->decimal('min_temperature', 5, 2)->nullable();
            $table->decimal('max_temperature', 5, 2)->nullable();
            $table->decimal('optimal_temperature_min', 5, 2)->nullable();
            $table->decimal('optimal_temperature_max', 5, 2)->nullable();

            // Electrical conductivity tolerance (Salinity)
            $table->decimal('max_ec', 7, 2)->nullable()->comment('Maximum salinity threshold before yield loss');
            $table->string('ec_unit', 20)->default('mS/cm');

            // Documentation & Provenance citations (prevent fabricated references)
            $table->string('source_citation')->nullable()->comment('e.g. FAO Irrigation and Drainage Paper 29 / DA-BAR');
            $table->string('source_url')->nullable();
            $table->text('applicability_notes')->nullable()->comment('Varieties, root depth, climate notes');
            $table->text('limitations_notes')->nullable()->comment('Specific caveats, e.g. requires calibrated volumetric water content');
            $table->timestamps();

            $table->index('crop_profile_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crop_requirements');
    }
};

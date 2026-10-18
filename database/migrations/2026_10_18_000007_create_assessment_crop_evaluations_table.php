<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_crop_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('soil_assessment_id')->constrained('soil_assessments')->cascadeOnDelete();
            $table->foreignId('crop_profile_id')->constrained('crop_profiles')->cascadeOnDelete();

            $table->string('suitability_tier')->default('Potential Match')->comment('Potential Match, Limited Match, Insufficient Data, Unsuitable');
            $table->string('tone', 20)->default('green')->comment('green, amber, gray, red');
            $table->decimal('match_score', 5, 2)->nullable()->comment('Calculated match percentage e.g. 85.00');
            $table->text('supporting_factors')->nullable()->comment('Explains which measurements fit referenced ranges');
            $table->text('limiting_factors')->nullable()->comment('Explains which measurements deviate or are uncalibrated');

            // Individual parameter checks
            $table->string('ph_status')->default('unknown')->comment('within_range, outside_range, unknown');
            $table->string('moisture_status')->default('uncalibrated')->comment('within_range, uncalibrated, deficit, excess, unknown');
            $table->string('temperature_status')->default('unknown')->comment('within_range, outside_range, unknown');
            $table->string('ec_status')->default('missing')->comment('tolerable, high_salinity, missing');

            $table->text('recommendation_notes')->nullable();
            $table->timestamps();

            $table->index(['soil_assessment_id', 'suitability_tier'], 'ace_assessment_tier_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_crop_evaluations');
    }
};

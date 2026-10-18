<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('soil_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('soil_sample_id')->constrained('soil_samples')->cascadeOnDelete();
            $table->dateTime('assessed_at');
            $table->string('assessment_method')->default('Rule-based Agronomic Model v1.0');
            $table->string('status')->default('preliminary')->comment('preliminary, reviewed, incomplete');
            $table->unsignedTinyInteger('usable_parameters_count')->default(0);
            $table->unsignedTinyInteger('total_parameters_count')->default(4);
            $table->text('summary')->nullable();
            $table->json('missing_information')->nullable()->comment('Array of unmeasured or uncalibrated parameters');
            $table->text('limitations')->nullable()->comment('Specific caveats regarding sensor bounds, nutrients, or texture');
            $table->text('recommended_next_steps')->nullable();
            $table->timestamps();

            $table->index(['soil_sample_id', 'assessed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('soil_assessments');
    }
};

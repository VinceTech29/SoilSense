<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fertilizer_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reading_id')->constrained('sensor_readings')->cascadeOnDelete();
            $table->string('crop', 100);
            $table->string('predicted_fertilizer', 100);
            $table->float('confidence');
            $table->json('top_predictions');
            $table->float('dose_kg_ha');
            $table->json('split_schedule');
            $table->json('rain_advisory');
            $table->string('model_version', 64);
            $table->boolean('fallback_used')->default(false);
            $table->timestamps();

            $table->index('reading_id');
            $table->index('crop');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fertilizer_recommendations');
    }
};

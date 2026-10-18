<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('soil_samples', function (Blueprint $table) {
            $table->id();
            $table->string('sample_code')->unique()->comment('Human-readable unique identifier e.g. SS-2025-018');
            $table->string('name')->comment('Descriptive sample name');
            $table->string('location')->nullable()->comment('Farm or plot location');
            $table->dateTime('collected_at')->comment('Sample collection timestamp');
            $table->string('testing_method')->default('prototype_sensor')->comment('prototype_sensor, manual_entry, core_sample');
            $table->string('source_type')->default('demo')->comment('live, manual, demo');
            $table->string('assessment_status')->default('preliminary')->comment('preliminary, reviewed, incomplete');
            $table->text('notes')->nullable();
            $table->string('operator_name')->nullable()->default('Juan Dela Cruz');
            $table->foreignId('device_id')->nullable()->constrained('iot_devices')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['collected_at', 'assessment_status']);
            $table->index('source_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('soil_samples');
    }
};

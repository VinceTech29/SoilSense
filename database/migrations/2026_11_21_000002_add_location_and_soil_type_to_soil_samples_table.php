<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('soil_samples', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('location');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->string('soil_type', 32)->nullable()->after('longitude')->comment('Sandy, Silt, Loamy, Clay');
        });
    }

    public function down(): void
    {
        Schema::table('soil_samples', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'soil_type']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sensor_readings', function (Blueprint $table) {
            $table->float('nitrogen')->nullable()->after('ec_unit')->comment('Available soil nitrogen (mg/kg)');
            $table->float('phosphorus')->nullable()->after('nitrogen')->comment('Available soil phosphorus (mg/kg)');
            $table->float('potassium')->nullable()->after('phosphorus')->comment('Available soil potassium (mg/kg)');
            $table->string('npk_source', 20)->nullable()->after('potassium')->comment('sensor, manual, demo');
        });
    }

    public function down(): void
    {
        Schema::table('sensor_readings', function (Blueprint $table) {
            $table->dropColumn(['nitrogen', 'phosphorus', 'potassium', 'npk_source']);
        });
    }
};

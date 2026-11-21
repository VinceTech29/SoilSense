<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = array_values(array_filter(
            ['nitrogen', 'phosphorus', 'potassium', 'npk_source'],
            fn (string $column): bool => Schema::hasColumn('sensor_readings', $column)
        ));

        if ($columns !== []) {
            Schema::table('sensor_readings', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }

    public function down(): void
    {
        Schema::table('sensor_readings', function (Blueprint $table) {
            if (!Schema::hasColumn('sensor_readings', 'nitrogen')) {
                $table->float('nitrogen')->nullable()->after('ec_unit')->comment('Available soil nitrogen (mg/kg)');
            }
            if (!Schema::hasColumn('sensor_readings', 'phosphorus')) {
                $table->float('phosphorus')->nullable()->after('nitrogen')->comment('Available soil phosphorus (mg/kg)');
            }
            if (!Schema::hasColumn('sensor_readings', 'potassium')) {
                $table->float('potassium')->nullable()->after('phosphorus')->comment('Available soil potassium (mg/kg)');
            }
            if (!Schema::hasColumn('sensor_readings', 'npk_source')) {
                $table->string('npk_source', 20)->nullable()->after('potassium')->comment('sensor, manual, demo');
            }
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crop_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique()->comment('Common name e.g. Tomato, Pechay, Eggplant');
            $table->string('scientific_name')->nullable()->comment('Botanical name e.g. Solanum lycopersicum');
            $table->string('category')->default('Fruit vegetables')->comment('Category e.g. Leafy vegetables, Fruit vegetables');
            $table->text('description')->nullable();
            $table->string('image_url')->nullable();
            $table->timestamps();

            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crop_profiles');
    }
};

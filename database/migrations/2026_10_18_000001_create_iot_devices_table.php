<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('iot_devices', function (Blueprint $table) {
            $table->id();
            $table->string('device_uid')->unique()->comment('Hardware UID e.g. ESP32-01');
            $table->string('name')->comment('Display name e.g. Primary soil station');
            $table->string('status')->default('online')->comment('online, offline, error, maintenance');
            $table->string('api_token_hash', 64)->nullable()->comment('SHA-256 token hash or token identifier');
            $table->string('location')->nullable();
            $table->boolean('is_demo')->default(false)->comment('Distinguishes prototype demo devices from live hardware');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iot_devices');
    }
};

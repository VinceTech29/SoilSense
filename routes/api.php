<?php

use App\Http\Controllers\Api\SensorReadingApiController;
use App\Http\Middleware\AuthenticateDevice;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - SoilSense IoT Telemetry Ingestion
|--------------------------------------------------------------------------
|
| Version 1 routes for ESP32 microcontroller communication and data ingestion.
| Authenticated via AuthenticateDevice middleware and rate limited.
|
*/

Route::prefix('v1')->group(function () {
    Route::post('/readings', [SensorReadingApiController::class, 'store'])
        ->middleware([AuthenticateDevice::class, 'throttle:60,1'])
        ->name('api.v1.readings.store');
});

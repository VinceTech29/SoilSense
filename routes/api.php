<?php

use App\Http\Controllers\Api\MobileApiController;
use App\Http\Controllers\Api\SensorReadingApiController;
use App\Http\Middleware\AuthenticateDevice;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - SoilSense IoT Telemetry Ingestion & Mobile API
|--------------------------------------------------------------------------
|
| Version 1 routes for ESP32 microcontroller communication and data ingestion,
| as well as REST API endpoints consumed by the Flutter Android mobile application.
|
*/

Route::prefix('v1')->group(function () {
    // ESP32 Telemetry Ingestion
    Route::post('/readings', [SensorReadingApiController::class, 'store'])
        ->middleware([AuthenticateDevice::class, 'throttle:60,1'])
        ->name('api.v1.readings.store');

    // Flutter Mobile Application Endpoints
    Route::prefix('mobile')->group(function () {
        Route::get('/dashboard', [MobileApiController::class, 'dashboard'])->name('api.v1.mobile.dashboard');
        Route::get('/telemetry/latest', [MobileApiController::class, 'latestTelemetry'])->name('api.v1.mobile.telemetry.latest');
        Route::get('/telemetry/history', [MobileApiController::class, 'telemetryHistory'])->name('api.v1.mobile.telemetry.history');
        Route::get('/crops', [MobileApiController::class, 'crops'])->name('api.v1.mobile.crops');
        Route::get('/crops/{name}', [MobileApiController::class, 'cropDetail'])->name('api.v1.mobile.crops.detail');
        Route::get('/samples', [MobileApiController::class, 'samples'])->name('api.v1.mobile.samples');
        Route::post('/samples', [MobileApiController::class, 'storeSample'])->name('api.v1.mobile.samples.store');
    });
});


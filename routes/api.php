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
        Route::get('/assessments/{id}', [MobileApiController::class, 'showAssessment'])->name('api.v1.mobile.assessments.show');
        Route::get('/reports', [MobileApiController::class, 'reports'])->name('api.v1.mobile.reports');
        Route::get('/reports/{id}', [MobileApiController::class, 'showReport'])->name('api.v1.mobile.reports.show');
        Route::get('/devices', [MobileApiController::class, 'devices'])->name('api.v1.mobile.devices');
        Route::get('/devices/{id}', [MobileApiController::class, 'showDevice'])->name('api.v1.mobile.devices.show');

        // Token-Authenticated Mobile Fertilizer Recommendation Endpoints
        Route::middleware([AuthenticateDevice::class])->group(function () {
            Route::get('/samples/{id}/fertilizer', [MobileApiController::class, 'sampleFertilizer'])
                ->name('api.v1.mobile.samples.fertilizer');
            Route::post('/samples/{id}/fertilizer', [MobileApiController::class, 'storeSampleFertilizer'])
                ->name('api.v1.mobile.samples.fertilizer.store');
        });
    });

    // ESP32 Hardware Remote Control Endpoints
    Route::get('/device/active-sample', [\App\Http\Controllers\SoilSenseController::class, 'getActiveSample'])
        ->name('api.v1.device.active_sample.get');
    Route::post('/device/active-sample', [\App\Http\Controllers\SoilSenseController::class, 'setActiveSample'])
        ->name('api.v1.device.active_sample.set');
    Route::post('/device/advance-sample', [\App\Http\Controllers\SoilSenseController::class, 'advanceSample'])
        ->name('api.v1.device.advance_sample.set');
    Route::get('/device/latest-reading', [\App\Http\Controllers\SoilSenseController::class, 'getLatestReading'])
        ->name('api.v1.device.latest_reading');
});


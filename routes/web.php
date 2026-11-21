<?php

use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SoilSenseController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SoilSense Web Routes
|--------------------------------------------------------------------------
*/

Route::get('/', [SoilSenseController::class, 'landing'])->name('landing');
Route::get('/dashboard', [SoilSenseController::class, 'dashboard'])->name('dashboard');
Route::get('/analysis', [SoilSenseController::class, 'analysis'])->name('analysis');
Route::post('/analysis', [SoilSenseController::class, 'storeSample'])->name('sample.store');
Route::get('/crops', [SoilSenseController::class, 'crops'])->name('crops');
Route::get('/crops/{name}', [SoilSenseController::class, 'cropDetail'])->name('crop.detail');
Route::get('/report/{id?}', [SoilSenseController::class, 'report'])->name('report');
Route::post('/report/{id}/npk', [SoilSenseController::class, 'storeManualNpk'])->name('report.npk.store');
Route::get('/history', [SoilSenseController::class, 'history'])->name('history');
Route::get('/reports', [SoilSenseController::class, 'reports'])->name('reports');
Route::get('/device', [SoilSenseController::class, 'device'])->name('device');

// Settings Module Routes
Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
Route::post('/settings/profile', [SettingsController::class, 'updateProfile'])->name('settings.profile');
Route::post('/settings/security', [SettingsController::class, 'updateSecurity'])->name('settings.security');
Route::post('/settings/preferences', [SettingsController::class, 'updatePreferences'])->name('settings.preferences');
Route::get('/settings/export/samples', [SettingsController::class, 'exportSamples'])->name('settings.export.samples');
Route::get('/settings/export/crops', [SettingsController::class, 'exportCrops'])->name('settings.export.crops');

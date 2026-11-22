<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Support\Facades\View::composer(['layouts.app', 'layouts.public', 'report'], function ($view) {
            $attributes = app()->bound('request') ? request()->attributes : null;

            if (!$view->offsetExists('currentUser')) {
                if ($attributes && $attributes->has('soilsense.current_user')) {
                    $user = $attributes->get('soilsense.current_user');
                } else {
                    try {
                        $user = \App\Models\User::first();
                    } catch (\Throwable $e) {
                        $user = null;
                    }
                    $attributes?->set('soilsense.current_user', $user);
                }
                $view->with('currentUser', $user);
            }

            if (!$view->offsetExists('sidebarDevice')) {
                if ($attributes && $attributes->has('soilsense.sidebar_device')) {
                    $device = $attributes->get('soilsense.sidebar_device');
                } else {
                    try {
                        $device = \App\Models\IotDevice::where('device_uid', 'ESP32-01')->first()
                            ?? \App\Models\IotDevice::first();
                    } catch (\Throwable $e) {
                        $device = null;
                    }
                    $attributes?->set('soilsense.sidebar_device', $device);
                }
                $view->with('sidebarDevice', $device);
            }
        });
    }
}

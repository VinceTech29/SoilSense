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
        \Illuminate\Support\Facades\View::composer('*', function ($view) {
            if (!$view->offsetExists('currentUser')) {
                try {
                    $user = \App\Models\User::first();
                    $view->with('currentUser', $user);
                } catch (\Throwable $e) {
                    $view->with('currentUser', null);
                }
            }

            if (!$view->offsetExists('sidebarDevice')) {
                try {
                    $device = \App\Models\IotDevice::where('device_uid', 'ESP32-01')->first()
                        ?? \App\Models\IotDevice::first();
                    $view->with('sidebarDevice', $device);
                } catch (\Throwable $e) {
                    $view->with('sidebarDevice', null);
                }
            }
        });
    }
}

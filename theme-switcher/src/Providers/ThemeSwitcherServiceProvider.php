<?php

namespace ThemeSwitcher\Providers;

use Illuminate\Support\ServiceProvider;

class ThemeSwitcherServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/theme-switcher.php',
            'theme-switcher'
        );
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
    }
}

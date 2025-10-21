<?php

namespace Davealex\LaravelServiceCaching;

use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

class LaravelServiceCachingServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/config.php' => config_path('laravel-service-caching.php'),
            ], 'config');
        }
    }

    /**
     * Register the application services.
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/config.php', 'laravel-service-caching');

        $this->app->singleton('laravel-service-caching', function ($app) {
            return new LaravelServiceCaching(
                $app->make(Repository::class),
                $app->make(Request::class),
                $app->make(CacheManager::class)
            );
        });
    }
}

<?php

namespace Chattrn\Connector;

use Illuminate\Support\ServiceProvider;
use Chattrn\Connector\Console\Commands\InstallCommand;
use Chattrn\Connector\Console\Commands\MapCommand;
use Chattrn\Connector\Console\Commands\CheckCommand;
use Chattrn\Connector\Console\Commands\RegisterCommand;
use Chattrn\Connector\Support\Settings;

class ChattrnServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/chattrn.php', 'chattrn');
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/chattrn.php');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Overlays any model/column mapping saved via chattrn:map or /chattrn/setup/map
        // onto config('chattrn.*') for this request - see Settings. Deferred to `booted()`
        // so it runs after the database connection is actually configured, and wrapped so a
        // DB that isn't reachable yet (e.g. mid-migration) never breaks the request - it
        // just falls back to whatever config/chattrn.php + env() already say.
        $this->app->booted(function () {
            try {
                Settings::applyOverrides();
                $token = \Chattrn\Connector\Support\Credentials::token();
                if ($token) {
                    config(['chattrn.access_token' => $token]);
                }
            } catch (\Throwable $e) {
                // fail open - config/chattrn.php + env() values still apply
            }
        });

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/chattrn.php' => config_path('chattrn.php'),
            ], 'chattrn-config');

            $this->commands([
                InstallCommand::class,
                MapCommand::class,
                CheckCommand::class,
                RegisterCommand::class,
            ]);
        }
    }
}

<?php

namespace Linups\OntraportLaravel;

use Illuminate\Support\ServiceProvider;
use Linups\OntraportLaravel\Support\SdkAutoloader;

class OntraportServiceProvider extends ServiceProvider
{
    // No return types on register()/boot(): subclasses overriding them without one must keep working.
    public function register()
    {
        SdkAutoloader::register();

        $this->mergeConfigFrom(__DIR__ . '/../config/ontraport.php', 'ontraport');

        $this->app->singleton(Ontraport::class);
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/ontraport.php' => $this->app->configPath('ontraport.php'),
            ], 'ontraport-config');
        }
    }
}

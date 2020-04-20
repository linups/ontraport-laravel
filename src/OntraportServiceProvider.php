<?php
namespace Linups\OntraportLaravel;

use Illuminate\Support\ServiceProvider;
use Linups\OntraportLaravel\Ontraport;

class OntraportServiceProvider extends ServiceProvider {
    
    public function boot() {
        $this->app->singleton('Linups\OntraportLaravel\Ontraport');
    }
    
    public function register() {
        
    }
}


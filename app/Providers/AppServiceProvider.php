<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL; // <-- Thêm import này
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // if (config('app.env') !== 'local') {
        //     URL::forceScheme('https');
        // }
    }
}

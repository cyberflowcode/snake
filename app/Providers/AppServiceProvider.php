<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        \Illuminate\Support\Facades\Schema::defaultStringLength(191);

        if (
            config('app.env') === 'production' ||
            request()->header('x-forwarded-proto') === 'https' ||
            (request()->server('HTTP_X_FORWARDED_PROTO') === 'https') ||
            str_contains(request()->url(), 'railway.app')
        ) {
            URL::forceScheme('https');
        }
    }
}

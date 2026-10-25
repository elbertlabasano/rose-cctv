<?php

namespace App\Providers;

use App\Contracts\TicketProvider;
use App\Integrations\HttpTicketProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(TicketProvider::class, HttpTicketProvider::class);
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

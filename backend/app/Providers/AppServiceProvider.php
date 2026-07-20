<?php

namespace App\Providers;

use App\Observers\ModelAuditObserver;
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
        foreach (config('audit.models', []) as $model) {
            if (class_exists($model)) {
                $model::observe(ModelAuditObserver::class);
            }
        }
    }
}

<?php

namespace App\Providers;

use App\Observers\ModelAuditObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        RateLimiter::for('login', function (Request $request) {
            if (app()->environment('testing')) {
                return Limit::none();
            }

            return Limit::perMinute(8)->by(
                strtolower((string) $request->ip()).'|'.strtolower((string) $request->input('username'))
            );
        });

        foreach (config('audit.models', []) as $model) {
            if (class_exists($model)) {
                $model::observe(ModelAuditObserver::class);
            }
        }
    }
}

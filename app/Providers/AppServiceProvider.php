<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
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
        $this->configureEloquent();
    }

    /**
     * Strict Eloquent outside production: lazy loading (N+1), silently discarded
     * attributes (mass assignment) and access to missing attributes all throw.
     * In production these stay off so a missed eager load degrades, not fails.
     */
    private function configureEloquent(): void
    {
        $strict = ! $this->app->isProduction();

        Model::shouldBeStrict($strict);
        Model::preventLazyLoading($strict);
    }
}

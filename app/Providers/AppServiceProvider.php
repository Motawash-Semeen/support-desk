<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
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
        $this->configureAuthorization();
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

    /**
     * Model policies (TicketPolicy, AttachmentPolicy) are auto-discovered from
     * App\Policies. Gates here cover abilities that have no model.
     */
    private function configureAuthorization(): void
    {
        Gate::define('view-dashboard', fn (User $user): bool => $user->isStaff());
        Gate::define('manage-sla-settings', fn (User $user): bool => $user->isAdmin());

        // Cosmetic only: hides staff UI. The server still authorizes every action.
        Blade::if('staff', fn (): bool => (bool) auth()->user()?->isStaff());
    }
}

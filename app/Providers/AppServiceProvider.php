<?php

namespace App\Providers;

use App\Models\ErrorLog;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Assignment\AssignmentStrategy;
use App\Services\Sla\BusinessHoursSlaCalculator;
use App\Services\Sla\SlaCalculator;
use App\Services\Sla\SlaPolicyRepository;
use App\Services\Sla\SlaRules;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // SLA: rules are cached per request/job (scoped), maths is pure.
        $this->app->scoped(SlaPolicyRepository::class);
        $this->app->bind(SlaRules::class, SlaPolicyRepository::class);
        $this->app->bind(SlaCalculator::class, BusinessHoursSlaCalculator::class);

        // Auto-assignment strategy chosen by name in config/support.php.
        $this->app->bind(AssignmentStrategy::class, function (Application $app): AssignmentStrategy {
            $name = (string) config('support.assignment.strategy');
            $class = config("support.assignment.strategies.{$name}");

            if (! is_string($class) || ! is_subclass_of($class, AssignmentStrategy::class)) {
                throw new InvalidArgumentException("Unknown assignment strategy [{$name}].");
            }

            return $app->make($class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureEloquent();
        $this->configureAuthorization();
        $this->configureRouteBindings();
        $this->configureRateLimits();

        // Breeze forms use Password::defaults(). Production also rejects
        // passwords found in known breaches (Have I Been Pwned k-anonymity API).
        Password::defaults(fn () => app()->isProduction()
            ? Password::min(10)->letters()->mixedCase()->numbers()->uncompromised()
            : Password::min(8));
    }

    /**
     * Per-user limits on the write-heavy and file endpoints (config
     * support.rate_limits, per minute). Guests are keyed by IP. Login keeps
     * Breeze's own throttle (5 attempts per email+IP).
     */
    private function configureRateLimits(): void
    {
        foreach (array_keys(config('support.rate_limits')) as $name) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute((int) config("support.rate_limits.{$name}"))
                ->by($name.':'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        }
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
        Gate::define('view-error-log', fn (User $user): bool => $user->isAdmin());
        Gate::define('view-unassigned-queue', fn (User $user): bool => $user->isStaff());
        Gate::define('search-customers', fn (User $user): bool => $user->isStaff());
        Gate::define('update-availability', fn (User $user): bool => $user->isAgent());
        // Every signed-in user has an inbox; queries are scoped to their own rows.
        Gate::define('use-notifications', fn (User $user): bool => true);

        // Cosmetic only: hides staff UI. The server still authorizes every action.
        Blade::if('staff', fn (): bool => (bool) auth()->user()?->isStaff());

        // Bell badge: one COUNT per request, however often the navigation renders.
        // Memoised on the request itself (not once()), so a long-running worker
        // can never carry one request's count into the next.
        View::composer('layouts.navigation', function ($view): void {
            $request = request();
            $user = $request->user();

            if ($user !== null && ! $request->attributes->has('unread_notifications')) {
                $request->attributes->set('unread_notifications', $user->unreadNotifications()->count());
            }

            $view->with('unreadNotifications', (int) $request->attributes->get('unread_notifications', 0));

            // Open-error badge for admins only (one indexed COUNT, memoised the same way).
            if ($user !== null && $user->isAdmin() && ! $request->attributes->has('open_errors')) {
                $request->attributes->set('open_errors', ErrorLog::query()->open()->count());
            }

            $view->with('openErrors', (int) $request->attributes->get('open_errors', 0));
        });
    }

    /**
     * {ticket} resolves through visibleTo(): a customer asking for someone
     * else's ticket number gets the same 404 as for a number that does not
     * exist. TicketPolicy (denyAsNotFound) is the second layer.
     */
    private function configureRouteBindings(): void
    {
        Route::bind('ticket', function (string $value): Ticket {
            $user = request()->user();

            return Ticket::query()
                ->where('ticket_number', $value)
                ->when($user instanceof User, fn ($query) => $query->visibleTo($user))
                ->firstOrFail();
        });
    }
}

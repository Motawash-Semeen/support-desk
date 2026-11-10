<?php

namespace App\Providers;

use App\Models\User;
use App\Modules\Conversation\AttachmentPolicy;
use App\Modules\Conversation\Models\Attachment;
use App\Modules\Sla\SlaService;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\TicketPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One SlaService per request or job, so the SLA rules are read once.
        $this->app->scoped(SlaService::class);
    }

    public function boot(): void
    {
        $this->configureEloquent();
        $this->configureModules();
        $this->configureAuthorization();
        $this->configureRouteBindings();
        $this->configureRateLimits();

        // Production also rejects passwords found in known breaches.
        Password::defaults(fn () => app()->isProduction()
            ? Password::min(10)->letters()->mixedCase()->numbers()->uncompromised()
            : Password::min(8));
    }

    /** Per-user rate limits from config support.rate_limits (per IP for guests). */
    private function configureRateLimits(): void
    {
        foreach (array_keys(config('support.rate_limits')) as $name) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute((int) config("support.rate_limits.{$name}"))
                ->by($name.':'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        }
    }

    /**
     * Strict Eloquent outside production: lazy loading, discarded attributes and
     * missing attributes throw. Production only degrades.
     */
    private function configureEloquent(): void
    {
        $strict = ! $this->app->isProduction();

        Model::shouldBeStrict($strict);
        Model::preventLazyLoading($strict);
    }

    /** Module models live outside App\Models, so their policies and factories are mapped here. */
    private function configureModules(): void
    {
        Gate::policy(Ticket::class, TicketPolicy::class);
        Gate::policy(Attachment::class, AttachmentPolicy::class);

        Factory::guessFactoryNamesUsing(
            fn (string $model): string => 'Database\\Factories\\'.class_basename($model).'Factory'
        );
    }

    /** The log viewer gate, the @staff directive and the bell count. */
    private function configureAuthorization(): void
    {
        // The log viewer's own gate (its routes also require role:admin).
        Gate::define('viewLogViewer', fn (?User $user): bool => (bool) $user?->isAdmin());

        // Only hides staff UI; the server still checks every action.
        Blade::if('staff', fn (): bool => (bool) auth()->user()?->isStaff());

        // Bell badge: one COUNT per request, kept on the request itself.
        View::composer('layouts.navigation', function ($view): void {
            $request = request();
            $user = $request->user();

            if ($user !== null && ! $request->attributes->has('unread_notifications')) {
                $request->attributes->set('unread_notifications', $user->unreadNotifications()->count());
            }

            $view->with('unreadNotifications', (int) $request->attributes->get('unread_notifications', 0));
        });
    }

    /**
     * {ticket} is the ticket number. For customers it is looked up among their own
     * tickets, so someone else's number is a 404. For staff the policy gives that 404,
     * so a lost accept race can still answer 409.
     */
    private function configureRouteBindings(): void
    {
        Route::bind('ticket', function (string $value): Ticket {
            $user = request()->user();

            return Ticket::query()
                ->where('ticket_number', $value)
                ->when($user instanceof User && $user->isCustomer(), fn ($query) => $query->visibleTo($user))
                ->firstOrFail();
        });
    }
}

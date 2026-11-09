<?php

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * Allowlist key: the route name, or "METHOD uri" for unnamed routes.
 */
function routeKey(RoutingRoute $route): string
{
    return $route->getName() ?? $route->methods()[0].' '.$route->uri();
}

/*
 * Turns "authorization is enforced on the server" into an automated check.
 * Every application route must satisfy one of:
 *   - guest route on the reviewed GUEST list below;
 *   - behind `auth` AND (role:* middleware, or an authorization call detectable
 *     in the controller action: $this->authorize / Gate::authorize|allows|denies /
 *     ->can( / ->cannot( / a FormRequest whose authorize() does real work);
 *   - behind `auth` AND on the reviewed SELF_SERVICE list (acts only on the
 *     signed-in user's own data, or is open to every signed-in user).
 * Adding a route without any of these fails the build until someone reviews it.
 */

const GUEST_ROUTES = [
    'login' => 'Breeze login form',
    'POST login' => 'Breeze login attempt (throttled: 5 per email+IP)',
    'register' => 'Breeze registration form',
    'POST register' => 'Breeze self-registration (always a customer: role is not fillable)',
    'password.request' => 'Breeze forgot-password form',
    'password.email' => 'Breeze sends reset link',
    'password.reset' => 'Breeze reset form (token checked on submit)',
    'password.store' => 'Breeze reset submit (token + email verified by the broker)',
    'GET /' => 'Static welcome page (no data)',
    'GET up' => 'Health check for load balancers (no data)',
];

const SELF_SERVICE_ROUTES = [
    'dashboard' => 'Redirects to the role home page; no data',
    'profile.edit' => 'Own profile only ($request->user())',
    'profile.update' => 'Own profile; ProfileUpdateRequest validates name/email only',
    'profile.destroy' => 'Own account; requires current password',
    'password.update' => 'Own password; requires current password',
    'password.confirm' => 'Re-enter own password (form)',
    'POST confirm-password' => 'Re-enter own password (submit)',
    'verification.notice' => 'Own email verification',
    'verification.verify' => 'Signed URL for own email',
    'verification.send' => 'Resend own verification email (throttled)',
    'logout' => 'Ends own session',
    'notifications.index' => 'Own notifications only ($request->user()->notifications())',
    'notifications.read' => 'Own notifications only; another user\'s id is a 404',
    'notifications.read-all' => 'Own notifications only',
    'tickets.index' => 'Every user may list tickets; the list is limited to what they may see (TicketFilter / visibleTo)',
    'tickets.create' => 'Every signed-in user may open a ticket (form)',
    'tickets.store' => 'Every signed-in user may open a ticket (rate limited)',
];

function actionAuthorizes(RoutingRoute $route): bool
{
    $uses = $route->getAction('uses');

    if (! is_string($uses) || ! str_contains($uses, '@')) {
        return false; // closures cannot be inspected: must be on a reviewed list
    }

    [$class, $method] = explode('@', $uses);
    $reflection = new ReflectionMethod($class, $method);

    // A FormRequest parameter whose authorize() is more than "return true".
    foreach ($reflection->getParameters() as $parameter) {
        $type = $parameter->getType();
        if ($type instanceof ReflectionNamedType && is_subclass_of($type->getName(), FormRequest::class)
            && method_exists($type->getName(), 'authorize')) {
            $body = methodSource(new ReflectionMethod($type->getName(), 'authorize'));
            if (! preg_match('/return\s+true\s*;/', $body)) {
                return true;
            }
        }
    }

    return (bool) preg_match('/\$this->authorize\(|Gate::(authorize|allows|denies|check)\(|->can\(|->cannot\(/', methodSource($reflection));
}

function methodSource(ReflectionMethod $method): string
{
    $lines = file($method->getFileName());

    return implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
}

test('every authenticated route is authorized by role middleware, a policy check, or a reviewed self-service entry', function () {
    $unprotected = [];

    foreach (Route::getRoutes() as $route) {
        $name = routeKey($route);
        $middleware = $route->gatherMiddleware();
        $authenticated = in_array('auth', $middleware, true) || collect($middleware)->contains(fn ($m) => str_starts_with((string) $m, 'auth:'));

        if (! $authenticated) {
            if (! array_key_exists($name, GUEST_ROUTES)) {
                $unprotected[] = "GUEST  {$route->methods()[0]} /{$route->uri()} ({$name})";
            }

            continue;
        }

        $hasRole = collect($middleware)->contains(fn ($m) => str_starts_with((string) $m, 'role:'));

        if (! $hasRole && ! actionAuthorizes($route) && ! array_key_exists($name, SELF_SERVICE_ROUTES)) {
            $unprotected[] = "NO-AUTHZ  {$route->methods()[0]} /{$route->uri()} ({$name}) -> {$route->getActionName()}";
        }
    }

    expect($unprotected)->toBe([], "Routes without detectable authorization:\n".implode("\n", $unprotected));
});

test('every action on one ticket or attachment asks the policy, even behind role middleware', function () {
    $missing = collect(Route::getRoutes())
        ->filter(fn ($route) => str_contains($route->uri(), '{ticket}') || $route->getName() === 'attachments.download')
        ->reject(fn ($route) => actionAuthorizes($route))
        ->map(fn ($route) => $route->getName().' -> '.$route->getActionName())
        ->values()
        ->all();

    expect($missing)->toBe([]);
});

test('the reviewed allowlists contain no stale entries', function () {
    $names = collect(Route::getRoutes())->map(fn ($route) => routeKey($route))->all();

    foreach (array_keys(SELF_SERVICE_ROUTES + GUEST_ROUTES) as $listed) {
        expect(in_array($listed, $names, true))->toBeTrue("Allowlisted route [{$listed}] no longer exists.");
    }
});

<?php

use App\Http\Middleware\EnsureRole;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

it('opens the staff dashboard to agents and admins only', function () {
    $this->actingAs(User::factory()->create())->get(route('staff.dashboard'))->assertForbidden();
    $this->actingAs(User::factory()->agent()->create())->get(route('staff.dashboard'))->assertOk();
    $this->actingAs(User::factory()->admin()->create())->get(route('staff.dashboard'))->assertOk();
});

it('opens admin pages to admins only', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.sla-settings.index'))->assertForbidden();
    $this->actingAs(User::factory()->agent()->create())->get(route('admin.sla-settings.index'))->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->get(route('admin.sla-settings.index'))->assertOk();
});

it('rejects unauthenticated requests', function () {
    $middleware = new EnsureRole;

    expect(fn () => $middleware->handle(Request::create('/'), fn () => response('ok'), 'admin'))
        ->toThrow(AuthenticationException::class);
});

it('fails loudly on an unknown role in a route definition', function () {
    $request = Request::create('/');
    $request->setUserResolver(fn () => User::factory()->make());

    expect(fn () => (new EnsureRole)->handle($request, fn () => response('ok'), 'superuser'))
        ->toThrow(ValueError::class);
});

it('puts every application route behind auth and the right role middleware', function () {
    $routes = collect(Route::getRoutes()->getRoutes())->keyBy(fn ($route) => $route->getName());

    foreach (['dashboard', 'tickets.index', 'tickets.show', 'attachments.download'] as $name) {
        expect($routes[$name]->gatherMiddleware())->toContain('auth');
    }

    expect($routes['staff.dashboard']->gatherMiddleware())->toContain('auth', 'role:admin,agent')
        ->and($routes['admin.sla-settings.index']->gatherMiddleware())->toContain('auth', 'role:admin');
});

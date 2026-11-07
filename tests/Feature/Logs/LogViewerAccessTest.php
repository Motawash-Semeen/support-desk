<?php

use App\Models\User;

/*
| opcodesio/log-viewer at /admin/log-viewer: raw files in storage/logs,
| the app's only log UI. Admins only, and it must work under our CSP.
*/

test('guests are sent to the login page', function () {
    $this->get('/admin/log-viewer')->assertRedirect(route('login'));
    $this->getJson('/admin/log-viewer/api/files')->assertUnauthorized();
});

test('agents and customers cannot open the log viewer or its API', function (string $role) {
    $user = User::factory()->{$role}()->create();

    $this->actingAs($user)->get('/admin/log-viewer')->assertForbidden();
    $this->actingAs($user)->getJson('/admin/log-viewer/api/files')->assertForbidden();
})->with(['agent', 'customer']);

test('admins can open the log viewer and its API', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/admin/log-viewer')->assertOk()->assertSee('id="log-viewer"', false);
    $this->actingAs($admin)->getJson('/admin/log-viewer/api/files')->assertOk();
});

test('the log viewer page keeps the CSP and nonces its inline scripts', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get('/admin/log-viewer')->assertOk();

    $csp = (string) $response->headers->get('Content-Security-Policy');
    expect($csp)->toContain("script-src 'self' 'nonce-");
    preg_match("/'nonce-([^']+)'/", $csp, $match);

    // Every inline <script> carries the request's nonce; none is left without one.
    preg_match_all('/<script(?![^>]*\bsrc=)([^>]*)>/', $response->getContent(), $scripts);
    expect($scripts[1])->not->toBeEmpty();
    foreach ($scripts[1] as $attributes) {
        expect($attributes)->toContain('nonce="'.$match[1].'"');
    }
    expect($response->getContent())->not->toContain('onerror="alert(');
});

test('admins see the log files link in the sidebar, agents do not', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('staff.dashboard'))
        ->assertSee(route('log-viewer.index'));

    $this->actingAs(User::factory()->agent()->create())
        ->get(route('staff.dashboard'))
        ->assertDontSee(route('log-viewer.index'));
});

<?php

use App\Models\User;

test('login screen can be rendered', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $this->assertGuest();
    $response->assertRedirect('/');
});

test('the sidebar logout control is a real form button that logs the user out', function () {
    $user = User::factory()->create();

    $html = $this->actingAs($user)->get(route('tickets.index'))->assertOk()->getContent();

    // A plain POST form with a submit button: inline onclick handlers are blocked by the CSP.
    expect($html)->toMatch('#<form method="POST" action="'.preg_quote(route('logout'), '#').'">\s*<input type="hidden" name="_token"[^>]*>\s*<button type="submit"#')
        ->and($html)->not->toContain('onclick=');

    $this->post(route('logout'))->assertRedirect('/');
    $this->assertGuest();
    $this->get(route('tickets.index'))->assertRedirect(route('login'));
});

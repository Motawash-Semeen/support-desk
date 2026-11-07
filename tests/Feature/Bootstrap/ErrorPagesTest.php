<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

test('a server error shows the custom 500 page without internals', function () {
    config(['app.debug' => false]);
    Route::middleware('web')->get('/_boom', fn () => throw new RuntimeException('Kaboom'));

    $this->get('/_boom')
        ->assertStatus(500)
        ->assertSee('Something went wrong')
        ->assertDontSee('Kaboom'); // internals never reach the visitor
});

test('missing pages use the custom 404 page', function () {
    $this->get('/no-such-page')->assertNotFound()->assertSee('Page not found')->assertSee('Go to dashboard');
});

test('any other client error falls back to the custom 4xx page', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('tickets.create'))
        ->assertStatus(405)
        ->assertSee('Method Not Allowed');
});

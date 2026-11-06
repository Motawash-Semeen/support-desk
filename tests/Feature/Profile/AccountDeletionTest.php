<?php

use App\Models\Ticket;
use App\Models\User;

it('refuses to delete an account that has ticket history', function () {
    $user = User::factory()->create();
    Ticket::factory()->for($user, 'creator')->create();

    $this->actingAs($user)
        ->from('/profile')
        ->delete('/profile', ['password' => 'password'])
        ->assertSessionHasErrorsIn('userDeletion', 'password')
        ->assertRedirect('/profile');

    expect($user->fresh())->not->toBeNull();
    $this->assertAuthenticated();
});

it('still deletes an account without ticket history', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->delete('/profile', ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    expect($user->fresh())->toBeNull();
});

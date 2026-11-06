<?php

use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\MissingAttributeException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;

it('enables strict Eloquent outside production', function () {
    expect(Model::preventsLazyLoading())->toBeTrue()
        ->and(Model::preventsSilentlyDiscardingAttributes())->toBeTrue()
        ->and(Model::preventsAccessingMissingAttributes())->toBeTrue();
});

it('throws on lazy loading a relation (N+1 guard)', function () {
    User::factory()->count(2)->create();

    $users = User::all();

    expect(fn () => $users->first()->notifications)
        ->toThrow(LazyLoadingViolationException::class);
});

it('allows the same relation when it is eager loaded', function () {
    User::factory()->count(2)->create();

    $users = User::with('notifications')->get();

    expect($users->first()->notifications)->toBeEmpty();
});

it('throws when a non-fillable attribute would be silently discarded', function () {
    expect(fn () => new User(['name' => 'A', 'is_admin' => true]))
        ->toThrow(MassAssignmentException::class);
});

it('throws when reading a column that was not selected', function () {
    User::factory()->create();

    $user = User::query()->select('id')->first();

    expect(fn () => $user->email)->toThrow(MissingAttributeException::class);
});

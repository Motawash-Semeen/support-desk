<?php

use App\Models\User;
use App\Modules\Logs\Models\ErrorLog;
use App\Modules\Logs\Services\ErrorRecorder;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $recorder = app(ErrorRecorder::class);
    $recorder->recordLog('critical', 'Queue worker crashed', ['queue' => 'default']);
    $recorder->recordException(new RuntimeException('SMTP connection refused'), 'error', ['mailer' => 'smtp']);
    $this->open = ErrorLog::query()->where('message', 'SMTP connection refused')->sole();
    $this->resolved = ErrorLog::query()->where('message', 'Queue worker crashed')->sole();
    $this->resolved->forceFill(['resolved_at' => now(), 'resolved_by' => $this->admin->id])->save();
});

test('only admins can open the error log', function (string $role) {
    $user = User::factory()->{$role}()->create();

    $this->actingAs($user)->get(route('admin.errors.index'))->assertForbidden();
    $this->actingAs($user)->get(route('admin.errors.show', $this->open))->assertForbidden();
    $this->actingAs($user)->patch(route('admin.errors.resolve', $this->open))->assertForbidden();
    $this->actingAs($user)->delete(route('admin.errors.destroy', $this->open))->assertForbidden();

    expect($this->open->fresh()->resolved_at)->toBeNull();
})->with(['agent', 'customer']);

test('admins see open errors first, can filter, and resolved ones under their tab', function () {
    $this->actingAs($this->admin)->get(route('admin.errors.index'))
        ->assertOk()
        ->assertSee('SMTP connection refused')
        ->assertDontSee('Queue worker crashed');

    $this->actingAs($this->admin)->get(route('admin.errors.index', ['status' => 'resolved']))
        ->assertOk()
        ->assertSee('Queue worker crashed')
        ->assertDontSee('SMTP connection refused');

    $this->actingAs($this->admin)->get(route('admin.errors.index', ['status' => 'all', 'q' => 'smtp']))
        ->assertSee('SMTP connection refused')
        ->assertDontSee('Queue worker crashed');
});

test('the detail page shows trace, context, location and latest request', function () {
    $this->actingAs($this->admin)->get(route('admin.errors.show', $this->open))
        ->assertOk()
        ->assertSee('SMTP connection refused')
        ->assertSee(RuntimeException::class)
        ->assertSee('Stack trace')
        ->assertSee('&quot;mailer&quot;: &quot;smtp&quot;', false)
        ->assertSee('ErrorLogPagesTest.php');
});

test('an admin resolves and reopens an error', function () {
    $this->actingAs($this->admin)->patch(route('admin.errors.resolve', $this->open))->assertRedirect();
    expect($this->open->fresh())->resolved_at->not->toBeNull()->resolved_by->toBe($this->admin->id);

    $this->actingAs($this->admin)->patch(route('admin.errors.resolve', $this->open))->assertRedirect();
    expect($this->open->fresh())->resolved_at->toBeNull()->resolved_by->toBeNull();
});

test('an admin deletes one error or clears all resolved ones', function () {
    $this->actingAs($this->admin)->delete(route('admin.errors.destroy', $this->open))->assertRedirect(route('admin.errors.index'));
    expect(ErrorLog::query()->whereKey($this->open->id)->exists())->toBeFalse();

    $this->actingAs($this->admin)->post(route('admin.errors.clear-resolved'))
        ->assertRedirect()
        ->assertSessionHas('status', 'Deleted 1 resolved error.');
    expect(ErrorLog::query()->count())->toBe(0);
});

test('admins see the open-error count in the sidebar and a banner on the dashboard', function () {
    $this->actingAs($this->admin)->get(route('staff.dashboard'))
        ->assertOk()
        ->assertSee('1 application error needs attention.')
        ->assertSee('Error log');

    $agent = User::factory()->agent()->create();
    $this->actingAs($agent)->get(route('staff.dashboard'))
        ->assertOk()
        ->assertDontSee('application error')
        ->assertDontSee('Error log');
});

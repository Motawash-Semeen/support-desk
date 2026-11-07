<?php

use App\Models\User;
use App\Modules\Logs\Models\ErrorLog;
use App\Modules\Logs\Services\ErrorRecorder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    ErrorRecorder::forgetLastRecordedId();
});

/** Throws from one fixed line, so repeated calls share a fingerprint. */
function throwTrackedError(string $message = 'Payment gateway timeout'): never
{
    throw new RuntimeException($message);
}

/* Recording ------------------------------------------------------------------ */

test('every reported exception is stored with its class, location and trace', function () {
    report(new LogicException('Ticket state is inconsistent'));

    $error = ErrorLog::query()->sole();

    expect($error->source)->toBe('exception')
        ->and($error->level)->toBe('error')
        ->and($error->message)->toBe('Ticket state is inconsistent')
        ->and($error->exception_class)->toBe(LogicException::class)
        ->and($error->file)->toContain('ErrorTrackerTest.php')
        ->and($error->line)->toBeGreaterThan(0)
        ->and($error->trace)->not->toBeEmpty()
        ->and($error->occurrences)->toBe(1)
        ->and($error->isResolved())->toBeFalse();
});

test('the same error is grouped: one row, an occurrence counter and the latest details', function () {
    foreach (['first', 'second', 'third'] as $attempt) {
        try {
            throwTrackedError("Gateway timeout ({$attempt})");
        } catch (RuntimeException $e) {
            report($e);
        }
    }

    $error = ErrorLog::query()->sole();

    expect($error->occurrences)->toBe(3)
        ->and($error->message)->toBe('Gateway timeout (third)');
});

test('a resolved error reopens when it happens again', function () {
    try {
        throwTrackedError();
    } catch (RuntimeException $e) {
        report($e);
    }
    ErrorLog::query()->sole()->forceFill(['resolved_at' => now()])->save();

    try {
        throwTrackedError();
    } catch (RuntimeException $e) {
        report($e);
    }

    expect(ErrorLog::query()->sole())
        ->resolved_at->toBeNull()
        ->occurrences->toBe(2);
});

test('logged errors are stored with secrets redacted; info and debug are not', function () {
    Log::info('Routine event');
    Log::error('Mail transport failed', ['password' => 'hunter2', 'api_key' => 'abc', 'ticket_id' => 42, 'nested' => ['token' => 'x', 'ok' => true]]);

    $error = ErrorLog::query()->sole();

    expect($error->source)->toBe('log')
        ->and($error->message)->toBe('Mail transport failed')
        ->and($error->context)->toBeJsonObject(['password' => '[redacted]', 'api_key' => '[redacted]', 'ticket_id' => 42, 'nested' => ['token' => '[redacted]', 'ok' => true]]);
});

test('the request, user and IP of the latest occurrence are recorded', function () {
    $user = User::factory()->admin()->create();
    Route::middleware('web')->get('/_tracked-failure', fn () => throwTrackedError('Request-time failure'));

    $this->actingAs($user)->get('/_tracked-failure?token=secret-value&page=2')->assertStatus(500);

    $error = ErrorLog::query()->where('source', 'exception')->sole();

    expect($error->method)->toBe('GET')
        ->and($error->url)->toContain('/_tracked-failure')
        ->and($error->url)->toContain('token=[redacted]')
        ->and($error->url)->not->toContain('secret-value')
        ->and($error->user_id)->toBe($user->id)
        ->and($error->ip)->toBe('127.0.0.1');
});

/* HTTP errors ------------------------------------------------------------------ */

test('4xx responses are recorded and grouped by status code', function () {
    $customer = User::factory()->customer()->create();

    $this->get('/does-not-exist')->assertNotFound();
    $this->get('/also-missing')->assertNotFound();
    $this->actingAs($customer)->get(route('admin.sla-settings.index'))->assertForbidden();

    $notFound = ErrorLog::query()->where('status_code', 404)->sole();
    $forbidden = ErrorLog::query()->where('status_code', 403)->sole();

    expect($notFound->source)->toBe('http')
        ->and($notFound->message)->toBe('HTTP 404 Not Found')
        ->and($notFound->occurrences)->toBe(2)
        ->and($notFound->url)->toContain('/also-missing')
        ->and($notFound->level)->toBe('notice')
        ->and($forbidden->level)->toBe('warning');
});

test('successful responses, conflicts and validation errors are not recorded', function () {
    $this->get('/')->assertOk();
    $this->postJson('/login', [])->assertStatus(422);

    expect(ErrorLog::query()->count())->toBe(0);
});

test('404s for files browsers probe on their own are not recorded', function () {
    foreach (['/robots.txt', '/apple-touch-icon-precomposed.png', '/.well-known/security.txt', '/sitemap.xml'] as $path) {
        $this->get($path)->assertNotFound();
    }

    expect(ErrorLog::query()->count())->toBe(0);
});

test('tracking can be switched off', function () {
    config(['support.error_tracker.enabled' => false]);

    report(new RuntimeException('Not tracked'));
    $this->get('/does-not-exist');

    expect(ErrorLog::query()->count())->toBe(0);
});

/* Error pages ------------------------------------------------------------------ */

test('a server error shows the custom 500 page with the error reference', function () {
    config(['app.debug' => false]);
    Route::middleware('web')->get('/_boom', fn () => throwTrackedError('Kaboom'));

    $response = $this->get('/_boom')->assertStatus(500);

    $reference = ErrorLog::query()->where('message', 'Kaboom')->value('id');
    $response->assertSee('Something went wrong')
        ->assertSee('#'.$reference)
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

/* Pruning ------------------------------------------------------------------------ */

test('old resolved errors and long-silent errors are pruned; recent ones are kept', function () {
    $recorder = app(ErrorRecorder::class);
    foreach (['old-resolved', 'stale-open', 'recent-resolved', 'recent-open'] as $message) {
        $recorder->recordLog('error', $message);
    }
    ErrorLog::query()->where('message', 'old-resolved')->update(['resolved_at' => now()->subDays(31)]);
    ErrorLog::query()->where('message', 'stale-open')->update(['last_seen_at' => now()->subDays(91)]);
    ErrorLog::query()->where('message', 'recent-resolved')->update(['resolved_at' => now()->subDay()]);

    expect((new ErrorLog)->prunable()->pluck('message')->sort()->values()->all())->toBe(['old-resolved', 'stale-open']);
});

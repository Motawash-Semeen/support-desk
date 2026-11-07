<?php

use App\Enums\Role;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/* Headers ------------------------------------------------------------------ */

test('every web response carries the security headers and a nonce-based CSP', function () {
    $customer = User::factory()->customer()->create();
    $ticket = Ticket::factory()->for($customer, 'creator')->create();

    foreach (['/login', route('tickets.index'), route('tickets.show', $ticket)] as $url) {
        $response = ($url === '/login' ? $this : $this->actingAs($customer))->get($url)->assertOk();

        expect($response->headers->get('X-Frame-Options'))->toBe('DENY')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
            ->and($response->headers->get('Content-Security-Policy'))
            ->toContain("default-src 'self'", "frame-ancestors 'none'", "object-src 'none'")
            ->toMatch("/script-src 'self' 'nonce-[A-Za-z0-9]+'/")
            ->not->toContain("script-src 'self' 'unsafe-inline'");
    }
});

test('inline scripts carry the CSP nonce of the response', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get(route('tickets.index'))->assertOk();
    preg_match("/'nonce-([A-Za-z0-9]+)'/", $response->headers->get('Content-Security-Policy'), $m);

    expect($response->getContent())->toContain('<script nonce="'.$m[1].'">');
});

test('HSTS is sent only over HTTPS', function () {
    expect($this->get('/login')->headers->has('Strict-Transport-Security'))->toBeFalse()
        ->and($this->get('https://localhost/login')->headers->get('Strict-Transport-Security'))->toContain('max-age=31536000');
});

/* Mass assignment ---------------------------------------------------------- */

test('registration always creates a customer, whatever extra fields are posted', function () {
    $this->post('/register', [
        'name' => 'Mallory', 'email' => 'mallory@example.com', 'password' => 'password', 'password_confirmation' => 'password',
        'role' => 'admin', 'is_available' => 0, 'email_verified_at' => now()->toDateTimeString(),
    ]);

    $user = User::query()->where('email', 'mallory@example.com')->sole();

    expect($user->role)->toBe(Role::Customer)
        ->and($user->is_available)->toBeTrue()
        ->and($user->email_verified_at)->toBeNull();
});

test('the profile form cannot change role or availability', function () {
    $agent = User::factory()->agent()->create();

    $this->actingAs($agent)->patch('/profile', ['name' => 'New', 'email' => $agent->email, 'role' => 'admin', 'is_available' => 0]);

    expect($agent->fresh()->role)->toBe(Role::Agent)
        ->and($agent->fresh()->is_available)->toBeTrue()
        ->and($agent->fresh()->name)->toBe('New');
});

test('no model disables mass-assignment protection', function () {
    foreach (File::allFiles(app_path('Models')) as $file) {
        expect($file->getContents())->not->toMatch('/\$guarded\s*=\s*\[\s*\]/')
            ->not->toContain('unguard(');
    }
});

/* Output escaping ---------------------------------------------------------- */

test('a script in a ticket subject or description is rendered as text', function () {
    $customer = User::factory()->customer()->create();
    $ticket = Ticket::factory()->for($customer, 'creator')->create([
        'subject' => '<script>alert(1)</script>',
        'description' => "Line one\n<img src=x onerror=alert(2)>",
    ]);

    $this->actingAs($customer)->get(route('tickets.show', $ticket))
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertDontSee('<img src=x onerror=alert(2)>', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
        ->assertSee('&lt;img src=x onerror=alert(2)&gt;', false);
});

test('no application view echoes raw, unescaped output', function () {
    $raw = collect(File::allFiles(resource_path('views')))
        ->reject(fn ($file) => str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR))
        ->filter(fn ($file) => str_contains($file->getContents(), '{!!'))
        ->map(fn ($file) => $file->getRelativePathname())
        ->values()
        ->all();

    expect($raw)->toBe([]);
});

/* Configuration ------------------------------------------------------------ */

test('env() is only called inside config files', function () {
    $offenders = collect([app_path(), base_path('routes'), database_path(), resource_path('views')])
        ->flatMap(fn ($dir) => File::allFiles($dir))
        ->filter(fn ($file) => preg_match('/(?<![\w>:$])env\(/', $file->getContents()))
        ->map(fn ($file) => $file->getRelativePathname())
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

test('no CSRF exceptions are configured', function () {
    expect(file_get_contents(base_path('bootstrap/app.php')))->not->toContain('validateCsrfTokens');
});

test('production defaults: debug off in the example env, secure session cookies', function () {
    $example = file_get_contents(base_path('.env.example'));

    expect($example)->toContain('# APP_DEBUG=false')
        ->and(config('session.http_only'))->toBeTrue()
        ->and(config('session.same_site'))->toBe('lax');

    // Secure cookies default to on in production unless explicitly overridden.
    expect(file_get_contents(config_path('session.php')))
        ->toContain("'secure' => env('SESSION_SECURE_COOKIE', env('APP_ENV') === 'production')");
});

test('attachment downloads keep their own sandboxing CSP', function () {
    $customer = User::factory()->customer()->create();
    $ticket = Ticket::factory()->for($customer, 'creator')->create();
    $attachment = Attachment::factory()->for($ticket)->create(['uploaded_by' => $customer->id]);
    Storage::disk('private')->put($attachment->stored_name, 'x');

    $response = $this->actingAs($customer)->get(route('attachments.download', $attachment))->assertOk();

    expect($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; img-src 'self'; sandbox")
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

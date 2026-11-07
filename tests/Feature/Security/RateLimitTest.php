<?php

use App\Models\User;
use App\Modules\Conversation\Models\Attachment;
use App\Modules\Tickets\Models\Category;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Queue::fake();
    config(['support.assignment.auto_on_create' => false]);
});

test('a customer is throttled after the per-minute ticket creation limit', function () {
    config(['support.rate_limits.tickets' => 2]);
    $customer = User::factory()->customer()->create();
    $payload = ['subject' => 'Help', 'description' => 'Something broke', 'priority' => 'low', 'category_id' => Category::factory()->create()->id];

    $this->actingAs($customer)->post(route('tickets.store'), $payload)->assertRedirect();
    $this->actingAs($customer)->post(route('tickets.store'), $payload)->assertRedirect();
    $this->actingAs($customer)->post(route('tickets.store'), $payload)->assertTooManyRequests();

    expect(Ticket::query()->count())->toBe(2);

    // Limits are per user: another customer is unaffected.
    $this->actingAs(User::factory()->customer()->create())->post(route('tickets.store'), $payload)->assertRedirect();

    // And they reset after a minute.
    $this->travel(61)->seconds();
    $this->actingAs($customer)->post(route('tickets.store'), $payload)->assertRedirect();
});

test('reply posting is throttled per user', function () {
    config(['support.rate_limits.replies' => 1]);
    $customer = User::factory()->customer()->create();
    $ticket = Ticket::factory()->for($customer, 'creator')->create();

    $this->actingAs($customer)->post(route('tickets.replies.store', $ticket), ['body' => 'one'])->assertRedirect();
    $this->actingAs($customer)->post(route('tickets.replies.store', $ticket), ['body' => 'two'])->assertTooManyRequests();
});

test('attachment downloads are throttled per user', function () {
    config(['support.rate_limits.downloads' => 1]);
    $customer = User::factory()->customer()->create();
    $ticket = Ticket::factory()->for($customer, 'creator')->create();
    $attachment = Attachment::factory()->for($ticket)->create(['uploaded_by' => $customer->id]);
    Storage::disk('private')->put($attachment->stored_name, 'x');

    $this->actingAs($customer)->get(route('attachments.download', $attachment))->assertOk();
    $this->actingAs($customer)->get(route('attachments.download', $attachment))->assertTooManyRequests();
});

test('import uploads are throttled per admin', function () {
    config(['support.rate_limits.imports' => 1]);
    $admin = User::factory()->admin()->create();
    $file = fn () => UploadedFile::fake()->createWithContent('t.csv', "external_reference\nA\n");

    $this->actingAs($admin)->post(route('admin.imports.store'), ['file' => $file()])->assertRedirect();
    $this->actingAs($admin)->post(route('admin.imports.store'), ['file' => $file()])->assertTooManyRequests();
});

test('login keeps the Breeze throttle of five attempts', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $attempt) {
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
    }

    $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])
        ->assertSessionHasErrors(['email' => trans('auth.throttle', ['seconds' => 60, 'minutes' => 1])]);
});

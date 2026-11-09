<?php

use Illuminate\Support\Facades\Storage;

it('exposes the business settings from config/support.php', function () {
    expect(config('support.timezone'))->toBe('Asia/Dhaka')
        ->and(config('support.ticket_prefix'))->toBe('TKT')
        ->and(config('support.ticket_number_padding'))->toBe(6)
        ->and(config('support.business_hours'))->toBe([
            'days' => [0, 1, 2, 3, 4],
            'start' => '09:00',
            'end' => '18:00',
        ])
        ->and(config('support.sla'))->toBe([
            'low' => 2880,
            'medium' => 1440,
            'high' => 480,
            'critical' => 120,
            'warning_threshold' => 0.8,
        ])
        ->and(config('support.attachments.disk'))->toBe('private')
        ->and(config('support.attachments.max_kb'))->toBe(5120)
        ->and(config('support.attachments.max_files'))->toBe(5)
        ->and(config('support.attachments.mimes'))->toContain('pdf', 'png', 'csv');
});

it('stores time in UTC at the application level', function () {
    expect(config('app.timezone'))->toBe('UTC')
        ->and(date_default_timezone_get())->toBe('UTC')
        ->and(now()->getTimezone()->getName())->toBe('UTC');
});

it('defines a private attachments disk that is never served by URL', function () {
    $disk = config('filesystems.disks.'.config('support.attachments.disk'));

    expect($disk)->not->toBeNull()
        ->and($disk['driver'])->toBe('local')
        ->and($disk['root'])->toBe(storage_path('app/private'))
        ->and($disk['visibility'])->toBe('private')
        ->and($disk['serve'])->toBeFalse()
        ->and(config('filesystems.disks.local.serve'))->toBeFalse()
        ->and(array_key_exists('url', $disk))->toBeFalse();
});

it('keeps the attachments disk usable through the Storage facade', function () {
    Storage::fake('private');

    Storage::disk(config('support.attachments.disk'))->put('probe.txt', 'ok');

    Storage::disk('private')->assertExists('probe.txt');
});

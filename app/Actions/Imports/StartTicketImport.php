<?php

namespace App\Actions\Imports;

use App\Enums\ImportStatus;
use App\Jobs\ProcessTicketImport;
use App\Models\TicketImport;
use App\Models\User;
use App\Support\SafeFilename;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Stores the uploaded CSV on the private disk as imports/{uuid}.csv, records a
 * pending import and queues ProcessTicketImport. Returns at once: the admin
 * watches progress on the status page.
 */
final class StartTicketImport
{
    public function __invoke(User $admin, UploadedFile $file): TicketImport
    {
        $disk = Storage::disk((string) config('support.attachments.disk'));
        $path = $disk->putFileAs('imports', $file, Str::uuid()->toString().'.csv');

        try {
            $import = TicketImport::query()->create([
                'user_id' => $admin->getKey(),
                'original_name' => SafeFilename::clean($file->getClientOriginalName(), 'import.csv'),
                'path' => $path,
                'status' => ImportStatus::Pending,
            ]);
        } catch (Throwable $e) {
            $disk->delete($path);

            throw $e;
        }

        ProcessTicketImport::dispatch($import->id)->afterCommit();

        Log::info('Ticket import queued.', ['import_id' => $import->id, 'admin_id' => $admin->id]);

        return $import;
    }
}

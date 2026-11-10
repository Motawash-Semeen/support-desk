<?php

namespace App\Modules\Imports;

use App\Modules\Imports\Models\TicketImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/** One summary for the admin who uploaded the file. */
class ImportFinishedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120, 600];

    public function __construct(public TicketImport $import) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function viaQueues(): array
    {
        return ['mail' => 'notifications', 'database' => 'default'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Import finished: {$this->import->original_name}")
            ->greeting("Hello {$notifiable->name},")
            ->line($this->message())
            ->action('View import', $this->url());
    }

    public function toArray(object $notifiable): array
    {
        return [
            'import_id' => $this->import->id,
            'message' => $this->message(),
            'url' => $this->url(),
        ];
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Import notification failed.', ['import_id' => $this->import->id, 'error' => $exception->getMessage()]);
    }

    private function message(): string
    {
        return sprintf(
            'Import of %s finished: %d imported, %d failed, of %d rows.',
            $this->import->original_name,
            $this->import->success_rows,
            $this->import->failed_rows,
            $this->import->total_rows,
        );
    }

    private function url(): string
    {
        return route('admin.imports.show', $this->import);
    }
}

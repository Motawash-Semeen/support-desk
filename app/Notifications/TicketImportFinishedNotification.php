<?php

namespace App\Notifications;

use App\Models\TicketImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One summary to the admin who uploaded the file, instead of a notification per
 * imported ticket. Same channels, queues and retries as ticket notifications.
 */
class TicketImportFinishedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(public TicketImport $import) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /** @return array<string, string> */
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

    /** @return array{import_id: int, message: string, url: string} */
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

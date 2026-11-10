<?php

namespace App\Modules\Notifications;

use App\Modules\Tickets\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one notification class for ticket events: TicketNotifier writes the text,
 * this class delivers it. Queued; mail on the `notifications` queue, the bell
 * (database) on `default`; 3 tries.
 */
class TicketNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120, 600];

    /** Set in via(), so failed() can log who it was for. */
    public int|string|null $recipientId = null;

    public function __construct(
        public Ticket $ticket,
        public string $kind,
        public string $subject,
        public string $message,
        public array $details = [],
    ) {}

    public function via(object $notifiable): array
    {
        $this->recipientId = $notifiable->getKey();

        return ['mail', 'database'];
    }

    public function viaQueues(): array
    {
        return ['mail' => 'notifications', 'database' => 'default'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("[{$this->ticket->ticket_number}] {$this->subject}")
            ->markdown('Notifications::mail.ticket', [
                'name' => $notifiable->name,
                'heading' => $this->subject,
                'message' => $this->message,
                'details' => $this->details,
                'url' => $this->url(),
                'ticket' => $this->ticket,
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'ticket_id' => $this->ticket->id,
            'ticket_number' => $this->ticket->ticket_number,
            'kind' => $this->kind,
            'message' => $this->message,
            'url' => $this->url(),
        ];
    }

    /** After the last failed try; the job stays in failed_jobs. */
    public function failed(Throwable $exception): void
    {
        Log::error('Ticket notification failed.', [
            'kind' => $this->kind,
            'ticket_id' => $this->ticket->id,
            'notifiable_id' => $this->recipientId,
            'error' => $exception->getMessage(),
        ]);
    }

    private function url(): string
    {
        return route('tickets.show', $this->ticket);
    }
}

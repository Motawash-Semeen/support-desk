<?php

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Base for every ticket notification:
 *  - queued (ShouldQueue): requests never wait for SMTP;
 *  - channels mail + database (the database channel powers the in-app bell);
 *  - mail on the dedicated `notifications` queue, database on `default`, so an
 *    email flood cannot delay assignment jobs;
 *  - 3 tries with backoff; failed() logs ticket and recipient, the job stays
 *    in failed_jobs for `queue:retry`.
 * Subclasses provide the wording only.
 */
abstract class TicketNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> seconds */
    public array $backoff = [30, 120, 600];

    /**
     * Recipient id, captured in via(): Laravel calls via() per notifiable and
     * then clones the notification for each channel job, so every queued job
     * knows who it was for when failed() runs.
     */
    public int|string|null $recipientId = null;

    public function __construct(public Ticket $ticket) {}

    abstract protected function subject(): string;

    /** One sentence, used in the email and the in-app list. */
    abstract protected function message(): string;

    /** @return list<string> extra lines for the email (e.g. a reply excerpt) */
    protected function details(): array
    {
        return [];
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        $this->recipientId = $notifiable->getKey();

        return ['mail', 'database'];
    }

    /** @return array<string, string> */
    public function viaQueues(): array
    {
        return [
            'mail' => 'notifications',
            'database' => 'default',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("[{$this->ticket->ticket_number}] {$this->subject()}")
            ->markdown('mail.tickets.notification', [
                'name' => $notifiable->name,
                'heading' => $this->subject(),
                'message' => $this->message(),
                'details' => $this->details(),
                'url' => $this->url(),
                'ticket' => $this->ticket,
            ]);
    }

    /** @return array{ticket_id: int, ticket_number: string, message: string, url: string} */
    public function toArray(object $notifiable): array
    {
        return [
            'ticket_id' => $this->ticket->id,
            'ticket_number' => $this->ticket->ticket_number,
            'message' => $this->message(),
            'url' => $this->url(),
        ];
    }

    /** Called by the queue after the last failed attempt; the job is kept in failed_jobs. */
    public function failed(Throwable $exception): void
    {
        Log::error('Ticket notification failed.', [
            'notification' => static::class,
            'ticket_id' => $this->ticket->id,
            'notifiable_id' => $this->recipientId,
            'error' => $exception->getMessage(),
        ]);
    }

    protected function url(): string
    {
        return route('tickets.show', $this->ticket);
    }
}

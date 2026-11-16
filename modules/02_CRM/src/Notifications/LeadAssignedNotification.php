<?php

declare(strict_types=1);

namespace Modules\CRM\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the sales rep a lead was routed to, or to the tenant administrators when no rule could assign it
 * ($unassigned = true).
 */
final class LeadAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly LeadNotificationPayload $lead,
        public readonly bool $unassigned = false,
    ) {
        // Never deliver for a lead whose transaction was rolled back.
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function title(): string
    {
        return $this->unassigned
            ? "Lead needs an owner: {$this->lead->label}"
            : "New lead assigned: {$this->lead->label}";
    }

    public function toMail(object $notifiable): MailMessage
    {
        $rating = $this->lead->rating !== null ? strtoupper($this->lead->rating) : 'N/A';

        $message = (new MailMessage)
            ->subject($this->title())
            ->line($this->unassigned
                ? 'No routing rule matched this lead, so it is waiting for a manual assignment.'
                : 'A new lead has been assigned to you.')
            ->line("Lead: {$this->lead->label}")
            ->line("Score: {$this->lead->score} ({$rating})")
            ->action('Open lead', $this->lead->url());

        return $message;
    }

    /**
     * @return array{lead_id: string, title: string, rating: ?string, score: int, url: string, kind: string}
     */
    public function toArray(object $notifiable): array
    {
        return $this->lead->toArray($this->title()) + ['kind' => $this->unassigned ? 'lead_unassigned' : 'lead_assigned'];
    }
}

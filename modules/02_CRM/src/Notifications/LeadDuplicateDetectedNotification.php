<?php

declare(strict_types=1);

namespace Modules\CRM\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the owner of an existing record when a new submission duplicates it: either merged automatically
 * ($merged = true) or recorded as a pending duplicate candidate to review.
 */
final class LeadDuplicateDetectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly LeadNotificationPayload $lead,
        public readonly string $matchType,
        public readonly bool $merged = false,
        public readonly ?string $duplicateId = null,
    ) {
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
        return $this->merged
            ? "Lead updated by a new submission: {$this->lead->label}"
            : "Possible duplicate lead: {$this->lead->label}";
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->line($this->merged
                ? 'A new submission matched one of your leads and its missing details were filled in automatically.'
                : 'A new lead looks like a record you own. Review it and merge or dismiss the duplicate.')
            ->line("Lead: {$this->lead->label}")
            ->line('Match: '.str_replace('_', ' ', $this->matchType))
            ->action('Open lead', $this->lead->url());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->lead->toArray($this->title()) + [
            'kind' => $this->merged ? 'lead_merged' : 'lead_duplicate',
            'match_type' => $this->matchType,
            'duplicate_id' => $this->duplicateId,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Modules\CRM\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\CRM\Models\Lead;
use Modules\CRM\Services\LeadScoringService;

/**
 * Tells the sales rep a lead has been assigned (or, with $duplicateOf, that a duplicate of their lead came in).
 */
final class LeadAssignedNotification extends Notification
{
    public function __construct(
        public readonly Lead $lead,
        public readonly ?Lead $duplicateOf = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database', PushGatewayChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->line($this->body());
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->duplicateOf ? 'lead_duplicate' : 'lead_assigned',
            'lead_id' => (string) $this->lead->id,
            'duplicate_of_id' => $this->duplicateOf ? (string) $this->duplicateOf->id : null,
            'score' => $this->lead->score,
            'rating' => $this->lead->rating,
            'title' => $this->title(),
            'body' => $this->body(),
        ];
    }

    /** @return array<string, mixed> */
    public function toPush(object $notifiable): array
    {
        return [
            'title' => $this->title(),
            'body' => $this->body(),
            'data' => ['lead_id' => (string) $this->lead->id, 'rating' => $this->lead->rating],
        ];
    }

    private function title(): string
    {
        return $this->duplicateOf
            ? __('crm.lead_notification_duplicate_title')
            : __('crm.lead_notification_assigned_title');
    }

    private function body(): string
    {
        return __('crm.lead_notification_body', [
            'name' => $this->lead->full_name !== '' ? $this->lead->full_name : (string) $this->lead->email,
            'company' => (string) $this->lead->company_name,
            'label' => LeadScoringService::labelFor($this->lead->rating),
            'score' => (int) $this->lead->score,
        ]);
    }
}

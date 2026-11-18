<?php

declare(strict_types=1);

namespace Modules\CRM\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\CRM\Models\Lead;
use Modules\CRM\Services\LeadScoringService;

/**
 * Sent to the sales rep a lead was routed to, or to the tenant administrators when no rule could assign it.
 * Supports both Lead instances and LeadNotificationPayload DTOs.
 */
final class LeadAssignedNotification extends Notification
{
    use Queueable;

    public readonly Lead|LeadNotificationPayload $lead;

    public readonly ?Lead $duplicateOf;

    public readonly bool $unassigned;

    public function __construct(
        Lead|LeadNotificationPayload $lead,
        Lead|bool|null $duplicateOf = null,
        bool $unassigned = false,
    ) {
        $this->lead = $lead;
        $this->duplicateOf = $duplicateOf instanceof Lead ? $duplicateOf : null;
        $this->unassigned = is_bool($duplicateOf) ? $duplicateOf : $unassigned;

        if ($lead instanceof LeadNotificationPayload) {
            $this->afterCommit();
        }
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        if ($this->lead instanceof LeadNotificationPayload) {
            return ['database', 'mail'];
        }

        return ['mail', 'database', PushGatewayChannel::class];
    }

    public function title(): string
    {
        if ($this->lead instanceof LeadNotificationPayload) {
            return $this->unassigned
                ? "Lead needs an owner: {$this->lead->label}"
                : "New lead assigned: {$this->lead->label}";
        }

        return $this->duplicateOf
            ? __('crm.lead_notification_duplicate_title')
            : __('crm.lead_notification_assigned_title');
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->lead instanceof LeadNotificationPayload) {
            $rating = $this->lead->rating !== null ? strtoupper($this->lead->rating) : 'N/A';

            return (new MailMessage)
                ->subject($this->title())
                ->line($this->unassigned
                    ? 'No routing rule matched this lead, so it is waiting for a manual assignment.'
                    : 'A new lead has been assigned to you.')
                ->line("Lead: {$this->lead->label}")
                ->line("Score: {$this->lead->score} ({$rating})")
                ->action('Open lead', $this->lead->url());
        }

        return (new MailMessage)
            ->subject($this->title())
            ->line($this->body());
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        if ($this->lead instanceof LeadNotificationPayload) {
            return $this->lead->toArray($this->title()) + ['kind' => $this->unassigned ? 'lead_unassigned' : 'lead_assigned'];
        }

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
        if ($this->lead instanceof LeadNotificationPayload) {
            return [
                'title' => $this->title(),
                'body' => $this->title(),
                'data' => ['lead_id' => $this->lead->leadId, 'rating' => $this->lead->rating],
            ];
        }

        return [
            'title' => $this->title(),
            'body' => $this->body(),
            'data' => ['lead_id' => (string) $this->lead->id, 'rating' => $this->lead->rating],
        ];
    }

    private function body(): string
    {
        if ($this->lead instanceof LeadNotificationPayload) {
            return $this->title();
        }

        return __('crm.lead_notification_body', [
            'name' => $this->lead->full_name !== '' ? $this->lead->full_name : (string) $this->lead->email,
            'company' => (string) $this->lead->company_name,
            'label' => LeadScoringService::labelFor($this->lead->rating),
            'score' => (int) $this->lead->score,
        ]);
    }
}

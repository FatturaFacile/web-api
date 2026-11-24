<?php

declare(strict_types=1);

namespace Modules\CRM\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\CRM\Models\Activity;

/**
 * Reminds the assignee of a CRM activity (call, meeting, task, follow-up) that it is due. Stored as a database
 * notification for the in-app bell and mailed when the user has an address, like the other staff notifications.
 */
final class ActivityReminderNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly Activity $activity) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return filled($notifiable->email ?? null) ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $replace = [
            'subject' => (string) $this->activity->subject,
            'due' => $this->activity->due_date?->format('d/m/Y H:i') ?? '-',
        ];

        return (new MailMessage)
            ->subject(__('crm.activity_reminder.subject', $replace))
            ->line(__('crm.activity_reminder.body', $replace));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'crm_activity_reminder',
            'activity_id' => (string) $this->activity->id,
            'subject' => (string) $this->activity->subject,
            'type' => (string) $this->activity->type,
            'due_date' => $this->activity->due_date?->toIso8601String(),
            'related_type' => $this->activity->related_type,
            'related_id' => $this->activity->related_id,
        ];
    }
}

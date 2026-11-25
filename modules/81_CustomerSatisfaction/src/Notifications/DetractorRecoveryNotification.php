<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Notifications;

use Illuminate\Notifications\Notification;
use Modules\CustomerSatisfaction\Events\DetractorRecoveryOpened;

/**
 * Database notification to the account manager and the support lead when a customer gives a detractor score. The text is built by
 * the client from the key (customerSatisfaction.notification.<key>) in the language of the user.
 */
final class DetractorRecoveryNotification extends Notification
{
    public function __construct(private readonly DetractorRecoveryOpened $event) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'key' => 'detractor_recovery_opened',
            'module' => 'customer_satisfaction',
            'case_id' => $this->event->caseId,
            'survey_response_id' => $this->event->surveyResponseId,
            'customer_id' => $this->event->customerId,
            'priority' => $this->event->priority,
            'sla_due_at' => $this->event->slaDueAt,
        ];
    }
}

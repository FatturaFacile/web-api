<?php

declare(strict_types=1);

namespace Modules\Integrations\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Tells the owners and admins that a webhook endpoint was disabled because it kept failing. Stored as a database
 * notification of the user.
 */
final class WebhookEndpointDisabledNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $endpointId,
        public readonly string $endpointName,
        public readonly string $reason,
    ) {}

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
            'activity' => 'webhook_endpoint_disabled',
            'webhook_endpoint_id' => $this->endpointId,
            'webhook_endpoint_name' => $this->endpointName,
            'reason' => $this->reason,
            'message' => __('webhooks.endpoint_disabled_notification', ['name' => $this->endpointName]),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Modules\CRM\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends mobile push notifications through the configured push gateway (crm_lead.push.url).
 * The gateway resolves the user's registered devices; a missing configuration is a silent no-op.
 */
final class PushGatewayChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        $url = config('crm_lead.push.url');

        if (empty($url) || ! method_exists($notification, 'toPush')) {
            return;
        }

        try {
            Http::timeout(5)
                ->withToken((string) config('crm_lead.push.token'))
                ->post((string) $url, ['user_id' => (string) $notifiable->getKey()] + $notification->toPush($notifiable));
        } catch (Throwable $e) {
            // A push outage must never block lead capture.
            Log::warning('Lead push notification failed', ['error' => $e->getMessage()]);
        }
    }
}

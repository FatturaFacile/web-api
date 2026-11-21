<?php

declare(strict_types=1);

namespace Modules\Hse\Infrastructure\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * An HSE alert (expiring or expired certificate, medical visit or PPE; an unfit judgment) stored as a database
 * notification. The payload never carries clinical data.
 */
final class HseAlertNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public readonly string $activity, public readonly array $payload) {}

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
        return ['activity' => $this->activity, ...$this->payload];
    }
}

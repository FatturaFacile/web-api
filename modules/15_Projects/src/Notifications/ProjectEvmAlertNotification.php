<?php

declare(strict_types=1);

namespace Modules\Projects\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * An earned value threshold alert stored as a database notification. It carries the translation key of the message
 * (`PROJECTS.EVM_ALERT_<METRIC>`, seeded in it, de, fr and en) and the figures, so every client renders it in the locale
 * of the reader.
 */
final class ProjectEvmAlertNotification extends Notification
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

<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Infrastructure\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Tells the finance team that a rate synchronisation failed and no rate was stored. Stored as a database notification: the
 * texts come from the translation keys of the payload.
 */
final class ExchangeRateSyncFailedNotification extends Notification
{
    use Queueable;

    public const string TitleKey = 'FINANCE.CURRENCIES.NOTIFICATION.SYNC_FAILED_TITLE';

    public const string BodyKey = 'FINANCE.CURRENCIES.NOTIFICATION.SYNC_FAILED_BODY';

    public function __construct(public readonly string $runId, public readonly string $provider, public readonly string $error) {}

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
            'title_key' => self::TitleKey,
            'body_key' => self::BodyKey,
            'run_id' => $this->runId,
            'provider' => strtoupper($this->provider),
            'error' => $this->error,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Infrastructure\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Tells the finance team that a currency with open exposure moved more than the company threshold in a day.
 */
final class ExchangeRateMovementNotification extends Notification
{
    use Queueable;

    public const string TitleKey = 'FINANCE.CURRENCIES.NOTIFICATION.RATE_MOVEMENT_TITLE';

    public const string BodyKey = 'FINANCE.CURRENCIES.NOTIFICATION.RATE_MOVEMENT_BODY';

    public function __construct(
        public readonly string $currency,
        public readonly string $functionalCurrency,
        public readonly string $changePercent,
        public readonly string $date,
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
            'title_key' => self::TitleKey,
            'body_key' => self::BodyKey,
            'currency' => $this->currency,
            'functional_currency' => $this->functionalCurrency,
            'change_percent' => $this->changePercent,
            'date' => $this->date,
        ];
    }
}

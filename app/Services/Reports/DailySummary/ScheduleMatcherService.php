<?php

declare(strict_types=1);

namespace App\Services\Reports\DailySummary;

use App\Models\NotificationPreference;
use Cron\CronExpression;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Evaluates whether a notification preference is due at a given local timestamp and computes its idempotency period key.
 */
final class ScheduleMatcherService
{
    /**
     * Returns the idempotency period key if the preference is due at $localNow, or null if not due.
     */
    public function duePeriodKey(NotificationPreference $preference, Carbon $localNow): ?string
    {
        if (! $preference->enabled || $preference->unsubscribed_at !== null) {
            return null;
        }

        $currentHm = $localNow->format('H:i');
        $sendTime = $preference->send_time ?: '07:00';

        $periodKey = match ($preference->frequency) {
            NotificationPreference::FREQUENCY_DAILY => $currentHm === $sendTime
                ? $localNow->format('Y-m-d')
                : null,

            NotificationPreference::FREQUENCY_WEEKLY => ($currentHm === $sendTime && $localNow->dayOfWeek === ($preference->day_of_week ?? 1))
                ? $localNow->format('o-\WW')
                : null,

            NotificationPreference::FREQUENCY_MONTHLY => ($currentHm === $sendTime && $localNow->day === min($preference->day_of_month ?? 1, $localNow->daysInMonth))
                ? $localNow->format('Y-m')
                : null,

            NotificationPreference::FREQUENCY_CUSTOM_CRON => $this->matchCustomCron($preference->custom_cron, $localNow),

            default => null,
        };

        if ($periodKey === null) {
            return null;
        }

        if ($preference->last_period_key === $periodKey) {
            return null;
        }

        return $periodKey;
    }

    private function matchCustomCron(?string $expression, Carbon $localNow): ?string
    {
        if ($expression === null || trim($expression) === '' || ! CronExpression::isValidExpression($expression)) {
            return null;
        }

        try {
            $cron = new CronExpression($expression);

            return $cron->isDue($localNow) ? $localNow->format('Y-m-d-H-i') : null;
        } catch (Throwable) {
            return null;
        }
    }
}

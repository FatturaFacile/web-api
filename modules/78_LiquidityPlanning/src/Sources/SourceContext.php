<?php

declare(strict_types=1);

namespace Modules\LiquidityPlanning\Sources;

use Carbon\CarbonImmutable;
use Modules\LiquidityPlanning\Models\LiquiditySettings;

/**
 * What a flow source needs to know: the tenant, the currency of the forecast, the window of the horizon and the settings.
 */
final readonly class SourceContext
{
    public function __construct(
        public string $tenantId,
        public string $currency,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public LiquiditySettings $settings,
    ) {}

    /**
     * The date as a day on or after the start of the horizon (overdue items fall on the first day).
     */
    public function onOrAfterStart(mixed $date): CarbonImmutable
    {
        return $date === null ? $this->start : CarbonImmutable::parse($date)->startOfDay()->max($this->start);
    }

    /**
     * Days of a free-text payment term ("30 gg", "60 days", "net 45"); thirty when none is written.
     */
    public static function termDays(?string $terms): int
    {
        return $terms !== null && preg_match('/(\d{1,3})/', $terms, $match) === 1 ? (int) $match[1] : 30;
    }
}

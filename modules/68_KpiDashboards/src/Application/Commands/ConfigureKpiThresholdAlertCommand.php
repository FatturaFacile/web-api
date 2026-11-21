<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Application\Commands;

use Modules\KpiDashboards\Domain\Enums\KpiCode;

/**
 * Creates or updates an alert rule such as "cash runway < 3 months" (warning 6, critical 3).
 */
final readonly class ConfigureKpiThresholdAlertCommand
{
    /**
     * @param  list<string>  $channels  in_app, push or email
     */
    public function __construct(
        public string $tenantId,
        public KpiCode $kpi,
        public string $operator,
        public ?float $warningValue,
        public ?float $criticalValue,
        public array $channels = ['in_app'],
        public int $cooldownMinutes = 1440,
        public bool $isActive = true,
        public ?string $ruleId = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Application\Handlers;

use Modules\KpiDashboards\Application\Commands\ConfigureKpiThresholdAlertCommand;
use Modules\KpiDashboards\Domain\Exceptions\KpiDashboardException;
use Modules\KpiDashboards\Domain\Exceptions\KpiNotFoundException;
use Modules\KpiDashboards\Infrastructure\Models\KpiThresholdRule;

final class ConfigureKpiThresholdAlertHandler
{
    private const array Channels = ['in_app', 'push', 'email'];

    public function handle(ConfigureKpiThresholdAlertCommand $command): KpiThresholdRule
    {
        if ($command->warningValue === null && $command->criticalValue === null) {
            throw new KpiDashboardException('A threshold needs a warning or a critical value.');
        }

        if (array_diff($command->channels, self::Channels) !== [] || $command->channels === []) {
            throw new KpiDashboardException('Channels are in_app, push or email.');
        }

        // The critical boundary is the stricter one: lower for "<", higher for ">".
        if ($command->warningValue !== null && $command->criticalValue !== null) {
            $stricterIsLower = in_array($command->operator, ['<', '<='], true);

            if ($stricterIsLower ? $command->criticalValue > $command->warningValue : $command->criticalValue < $command->warningValue) {
                throw new KpiDashboardException('The critical value must be stricter than the warning value.');
            }
        }

        $attributes = [
            'kpi_code' => $command->kpi,
            'operator' => $command->operator,
            'warning_value' => $command->warningValue,
            'critical_value' => $command->criticalValue,
            'channels_json' => array_values(array_unique($command->channels)),
            'cooldown_minutes' => $command->cooldownMinutes,
            'is_active' => $command->isActive,
        ];

        if ($command->ruleId === null) {
            return KpiThresholdRule::create(['tenant_id' => $command->tenantId, ...$attributes]);
        }

        $rule = KpiThresholdRule::query()->where('tenant_id', $command->tenantId)->whereKey($command->ruleId)->first()
            ?? throw new KpiNotFoundException('Unknown threshold rule.');
        $rule->update($attributes);

        return $rule;
    }
}

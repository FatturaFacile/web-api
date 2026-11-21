<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Infrastructure\Services;

use Illuminate\Support\Facades\Log;
use Modules\KpiDashboards\Domain\Enums\KpiCode;
use Modules\KpiDashboards\Infrastructure\Models\KpiThresholdAlertLog;
use Modules\KpiDashboards\Infrastructure\Models\KpiThresholdRule;

/**
 * Raises a threshold alert when a KPI crosses the boundary of an active rule. While the cooldown of an earlier alert of
 * the same rule and severity runs, the breach is not raised again (deduplication). The alert is recorded with the channels
 * it was dispatched to: `in_app` is shown in the cockpit, `push` is handed to the notification gateway of the mobile apps.
 */
final class ThresholdAlertEvaluator
{
    /**
     * @return list<KpiThresholdAlertLog> the alerts raised now
     */
    public function evaluate(string $tenantId, KpiCode $kpi, ?float $value): array
    {
        if ($value === null) {
            return [];
        }

        $raised = [];

        foreach (KpiThresholdRule::query()->where('tenant_id', $tenantId)->where('kpi_code', $kpi->value)->where('is_active', true)->get() as $rule) {
            $severity = $rule->severityOf($value);

            if ($severity === null) {
                continue;
            }

            $cooling = KpiThresholdAlertLog::query()
                ->where('tenant_id', $tenantId)->where('rule_id', $rule->id)->where('severity', $severity)
                ->where('cooldown_until', '>', now())->exists();

            if ($cooling) {
                continue;
            }

            $channels = $rule->channels_json ?: ['in_app'];
            $alert = KpiThresholdAlertLog::create([
                'tenant_id' => $tenantId,
                'rule_id' => $rule->id,
                'kpi_code' => $kpi,
                'severity' => $severity,
                'observed_value' => $value,
                'threshold_value' => $severity === 'critical' ? $rule->critical_value : $rule->warning_value,
                'status' => 'open',
                'dispatched_channels_json' => $channels,
                'triggered_at' => now(),
                'cooldown_until' => now()->addMinutes($rule->cooldown_minutes),
            ]);

            if (in_array('push', $channels, true)) {
                // Deep link opened by the mobile apps: the role cockpit focused on the KPI and its drill-down.
                Log::info('KPI threshold push queued', ['tenant_id' => $tenantId, 'kpi' => $kpi->value, 'severity' => $severity, 'deep_link' => "fatturafacile://kpi/{$kpi->role()->value}/{$kpi->value}?drill=1"]);
            }

            $raised[] = $alert;
        }

        return $raised;
    }
}

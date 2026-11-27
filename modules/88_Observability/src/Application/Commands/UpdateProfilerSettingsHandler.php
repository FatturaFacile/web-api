<?php

declare(strict_types=1);

namespace Modules\Observability\Application\Commands;

use Modules\Observability\Profiler\ProfilerSettings;

final class UpdateProfilerSettingsHandler
{
    public function __construct(private readonly ProfilerSettings $settings) {}

    /**
     * @return array{slow_query_threshold_ms: int, n_plus_one_threshold: int, explain_enabled: bool, alert_endpoint_p95_ms: int, alert_tenant_share_pct: float, alert_connector_latency_ms: int, retention_days: int}
     */
    public function handle(UpdateProfilerSettingsCommand $command): array
    {
        return $this->settings->update($command->values);
    }
}

<?php

declare(strict_types=1);

namespace Modules\Observability\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Slow-query threshold and predictive alerting thresholds.
 */
final class ProfilerSettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $row */
        $row = $this->resource;

        return [
            'slow_query_threshold_ms' => $row['slow_query_threshold_ms'],
            'n_plus_one_threshold' => $row['n_plus_one_threshold'],
            'explain_enabled' => $row['explain_enabled'],
            'alert_endpoint_p95_ms' => $row['alert_endpoint_p95_ms'],
            'alert_tenant_share_pct' => $row['alert_tenant_share_pct'],
            'alert_connector_latency_ms' => $row['alert_connector_latency_ms'],
            'retention_days' => $row['retention_days'],
        ];
    }
}

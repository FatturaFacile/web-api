<?php

declare(strict_types=1);

namespace Modules\Observability\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Status of one institutional connector (the administrator view carries the error and the TLS days, the tenant view does not).
 */
final class ConnectorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $row */
        $row = $this->resource;

        return [
            'key' => $row['key'],
            'name_code' => $row['name_code'],
            'country' => $row['country'],
            'group' => $row['group'],
            'status' => $row['status'],
            'uptime_24h' => $row['uptime_24h'],
            'uptime_7d' => $row['uptime_7d'],
            'avg_latency_ms' => $row['avg_latency_ms'],
            'last_latency_ms' => $row['last_latency_ms'],
            'last_checked_at' => $row['last_checked_at'],
            'last_error' => $row['last_error'],
            'tls_days_remaining' => $row['tls_days_remaining'],
            'maintenance_message' => $row['maintenance_message'],
            'history' => $row['history'],
        ];
    }
}

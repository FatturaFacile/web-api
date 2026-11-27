<?php

declare(strict_types=1);

namespace Modules\Observability\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One API endpoint of the top-slowest list.
 */
final class SlowEndpointResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $row */
        $row = $this->resource;

        return [
            'method' => $row['method'],
            'route' => $row['route'],
            'requests' => $row['requests'],
            'avg_ms' => $row['avg_ms'],
            'p50_ms' => $row['p50_ms'],
            'p95_ms' => $row['p95_ms'],
            'p99_ms' => $row['p99_ms'],
            'avg_db_queries' => $row['avg_db_queries'],
            'avg_db_ms' => $row['avg_db_ms'],
            'error_rate' => $row['error_rate'],
            'last_trace_id' => $row['last_trace_id'],
        ];
    }
}

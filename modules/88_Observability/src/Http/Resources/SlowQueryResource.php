<?php

declare(strict_types=1);

namespace Modules\Observability\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One slow statement (or N+1 pattern) of the SQL profiler, as aggregated by TopSlowQueriesHandler.
 */
final class SlowQueryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $row */
        $row = $this->resource;

        return [
            'fingerprint' => $row['fingerprint'],
            'sql' => $row['sql'],
            'kind' => $row['kind'],
            'executions' => $row['executions'],
            'avg_ms' => $row['avg_ms'],
            'max_ms' => $row['max_ms'],
            'last_captured_at' => $row['last_captured_at'],
            'tenant_id' => $row['tenant_id'],
            'trace_id' => $row['trace_id'],
            'origin_type' => $row['origin_type'],
            'origin' => $row['origin'],
            'n_plus_one' => $row['n_plus_one'],
            'n_plus_one_count' => $row['n_plus_one_count'],
            'plan_status' => $row['plan_status'],
            'plan' => $row['plan'],
            'suggested_indexes' => $row['suggested_indexes'],
        ];
    }
}

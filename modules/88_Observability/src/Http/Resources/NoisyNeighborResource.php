<?php

declare(strict_types=1);

namespace Modules\Observability\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Database time share of one tenant in the window.
 */
final class NoisyNeighborResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $row */
        $row = $this->resource;

        return [
            'tenant_id' => $row['tenant_id'],
            'tenant_name' => $row['tenant_name'],
            'requests' => $row['requests'],
            'db_ms' => $row['db_ms'],
            'share_pct' => $row['share_pct'],
            'flagged' => $row['flagged'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace Modules\Intercompany\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Intercompany\Models\ReconciliationBatch;

/**
 * @mixin ReconciliationBatch
 */
final class ReconciliationBatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'group_id' => (string) $this->group_id,
            'as_of_date' => $this->as_of_date->toDateString(),
            'status' => $this->status,
            'totals' => $this->totals ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

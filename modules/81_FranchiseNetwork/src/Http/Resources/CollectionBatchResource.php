<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FranchiseNetwork\Models\CollectionBatch;

/**
 * @mixin CollectionBatch
 */
final class CollectionBatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'run_id' => $this->run_id,
            'scheme' => $this->scheme->value,
            'status' => $this->status,
            'total_amount' => $this->total_amount,
            'currency' => $this->currency,
            'collection_date' => $this->collection_date->toDateString(),
            'items' => $this->items,
        ];
    }
}

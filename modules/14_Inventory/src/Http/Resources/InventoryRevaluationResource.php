<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Models\InventoryRevaluation;
use Modules\Inventory\Support\Decimal;

/**
 * @mixin InventoryRevaluation
 */
final class InventoryRevaluationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var InventoryRevaluation $revaluation */
        $revaluation = $this->resource;

        return [
            'id' => $revaluation->id,
            'source_type' => $revaluation->source_type,
            'source_id' => $revaluation->source_id,
            'status' => $revaluation->status,
            'posting_date' => $revaluation->posting_date->toDateString(),
            'total_inventory_delta' => Decimal::money((string) $revaluation->total_inventory_delta),
            'total_cogs_delta' => Decimal::money((string) $revaluation->total_cogs_delta),
            'total_variance_delta' => Decimal::money((string) $revaluation->total_variance_delta),
            'journal_entry_id' => $revaluation->journal_entry_id,
            'lines' => $revaluation->lines ?? [],
        ];
    }
}

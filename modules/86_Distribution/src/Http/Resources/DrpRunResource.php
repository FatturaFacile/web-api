<?php

declare(strict_types=1);

namespace Modules\Distribution\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Distribution\Models\DrpRun;

/**
 * @mixin DrpRun
 */
final class DrpRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $summary = static fn ($warehouse): ?array => $warehouse === null ? null : ['id' => (string) $warehouse->id, 'code' => (string) $warehouse->code, 'name' => (string) $warehouse->name];

        return [
            'id' => $this->id,
            'run_number' => $this->run_number,
            'bucket_type' => $this->bucket_type->value,
            'horizon' => $this->horizon,
            'status' => $this->status->value,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'summary' => $this->summary ?? [],
            'alerts' => $this->whenLoaded('alerts', fn (): array => $this->alerts->map(static fn ($alert): array => [
                'id' => $alert->id,
                'type' => $alert->alert_type->value,
                'severity' => $alert->severity,
                'warehouse_id' => $alert->warehouse_id,
                'stock_item_id' => $alert->stock_item_id,
                'bucket_index' => $alert->bucket_index,
                'transfer_order_id' => $alert->transfer_order_id,
                'warehouse' => $summary($alert->warehouse),
                'item' => $alert->item === null ? null : ['id' => $alert->item->id, 'sku' => $alert->item->sku, 'name' => $alert->item->name, 'uom' => $alert->item->uom],
                'params' => $alert->params ?? [],
            ])->values()->all()),
        ];
    }
}

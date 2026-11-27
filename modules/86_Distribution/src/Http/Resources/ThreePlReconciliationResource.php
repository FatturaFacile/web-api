<?php

declare(strict_types=1);

namespace Modules\Distribution\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Distribution\Models\ThreePlReconciliation;

/**
 * @mixin ThreePlReconciliation
 */
final class ThreePlReconciliationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'warehouse' => $this->whenLoaded('warehouse', fn (): array => ['id' => (string) $this->warehouse->id, 'code' => (string) $this->warehouse->code, 'name' => (string) $this->warehouse->name]),
            'external_reference' => $this->external_reference,
            'status' => $this->status,
            'lines' => $this->lines,
            'reconciled_at' => $this->reconciled_at?->toIso8601String(),
        ];
    }
}

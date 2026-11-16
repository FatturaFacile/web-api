<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SupplierPortal\Models\SupplierCheck;

/**
 * A verification of a supplier and its outcome.
 *
 * @mixin SupplierCheck
 */
final class SupplierCheckResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'supplier_id' => $this->supplier_id,
            'check_type' => $this->check_type,
            'result' => $this->result,
            'checked_at' => $this->checked_at?->toDateString(),
            'valid_until' => $this->valid_until?->toDateString(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

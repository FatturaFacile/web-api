<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AccountsPayable\Models\ApSupplierTerm;

/**
 * A cash-discount agreement with a supplier.
 *
 * @mixin ApSupplierTerm
 */
final class SupplierTermResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'supplier_id' => $this->supplier_id,
            'skonto_percentage' => (string) $this->skonto_percentage,
            'skonto_days' => $this->skonto_days,
            'net_days' => $this->net_days,
            'clause' => $this->clause,
            'reference' => $this->reference,
            'valid_from' => $this->valid_from?->toDateString(),
            'valid_to' => $this->valid_to?->toDateString(),
            'is_active' => $this->is_active,
            'notes' => $this->notes,
        ];
    }
}

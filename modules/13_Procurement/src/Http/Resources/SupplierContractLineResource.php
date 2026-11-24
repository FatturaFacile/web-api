<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Procurement\Models\SupplierContractLine;
use Modules\Procurement\Support\Decimal;

/**
 * An item of a framework agreement with its agreed price, validity and consumption.
 *
 * @mixin SupplierContractLine
 */
final class SupplierContractLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'supplier_contract_id' => (string) $this->supplier_contract_id,
            'supplier_catalog_id' => $this->supplier_catalog_id,
            'description' => $this->description,
            'uom' => $this->uom,
            'unit_price' => (string) $this->unit_price,
            'tax_rate' => (string) $this->tax_rate,
            'committed_quantity' => $this->committed_quantity !== null ? (string) $this->committed_quantity : null,
            'released_quantity' => (string) $this->released_quantity,
            'remaining_quantity' => $this->committed_quantity !== null ? Decimal::sub((string) $this->committed_quantity, (string) $this->released_quantity, 4) : null,
            'price_valid_from' => $this->price_valid_from->toDateString(),
            'price_valid_to' => $this->price_valid_to?->toDateString(),
        ];
    }
}

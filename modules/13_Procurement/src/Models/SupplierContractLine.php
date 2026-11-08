<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An item of a framework (blanket) agreement: the agreed price, its validity and the committed quantity that call-off
 * orders consume.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $supplier_contract_id
 * @property string|null $supplier_catalog_id
 * @property string $description
 * @property string $uom
 * @property string $unit_price
 * @property string $tax_rate
 * @property string|null $committed_quantity
 * @property string $released_quantity
 * @property Carbon $price_valid_from
 * @property Carbon|null $price_valid_to
 * @property SupplierCatalog|null $catalog
 */
final class SupplierContractLine extends BaseEnterpriseModel
{
    protected $table = 'supplier_contract_lines';

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:4',
            'tax_rate' => 'decimal:2',
            'committed_quantity' => 'decimal:4',
            'released_quantity' => 'decimal:4',
            'price_valid_from' => 'date',
            'price_valid_to' => 'date',
        ];
    }

    /**
     * Whether the agreed price applies on the given date.
     */
    public function isPriceValidOn(string $date): bool
    {
        return $this->price_valid_from->toDateString() <= $date
            && ($this->price_valid_to === null || $this->price_valid_to->toDateString() >= $date);
    }

    /**
     * @return BelongsTo<SupplierContract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(SupplierContract::class, 'supplier_contract_id');
    }

    /**
     * @return BelongsTo<SupplierCatalog, $this>
     */
    public function catalog(): BelongsTo
    {
        return $this->belongsTo(SupplierCatalog::class, 'supplier_catalog_id');
    }
}

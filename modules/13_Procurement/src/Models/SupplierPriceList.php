<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eloquent model of the SupplierPriceList records of the procurement module.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $supplier_catalog_id
 * @property string $currency
 * @property string $min_quantity
 * @property string $unit_price
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_to
 */
final class SupplierPriceList extends BaseEnterpriseModel
{
    protected $table = 'supplier_price_lists';

    protected function casts(): array
    {
        return [
            'min_quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    /**
     * @return BelongsTo<SupplierCatalog, $this>
     */
    public function catalog(): BelongsTo
    {
        return $this->belongsTo(SupplierCatalog::class, 'supplier_catalog_id');
    }
}

<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model of the SupplierCatalog records of the procurement module.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $supplier_id
 * @property string $supplier_part_number
 * @property string|null $internal_sku
 * @property string $name
 * @property string|null $description
 * @property string $uom
 * @property int $lead_time_days
 * @property string $minimum_order_quantity
 * @property bool $is_active
 */
final class SupplierCatalog extends BaseEnterpriseModel
{
    protected $table = 'supplier_catalogs';

    protected function casts(): array
    {
        return [
            'lead_time_days' => 'integer',
            'minimum_order_quantity' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    /**
     * @return HasMany<SupplierPriceList, $this>
     */
    public function priceLists(): HasMany
    {
        return $this->hasMany(SupplierPriceList::class, 'supplier_catalog_id');
    }
}

<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function priceLists(): HasMany
    {
        return $this->hasMany(SupplierPriceList::class, 'supplier_catalog_id');
    }
}

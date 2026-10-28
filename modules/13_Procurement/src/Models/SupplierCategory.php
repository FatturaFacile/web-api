<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SupplierCategory extends BaseEnterpriseModel
{
    protected $table = 'supplier_categories';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class, 'supplier_category_id');
    }
}

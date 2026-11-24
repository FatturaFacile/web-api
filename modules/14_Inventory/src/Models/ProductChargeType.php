<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ProductChargeType extends BaseEnterpriseModel
{
    use AllowsGlobalRecords;

    protected $table = 'product_charge_types';

    public const array BASES = ['per_unit', 'per_kg', 'per_litre', 'percent_of_net'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<ProductChargeRate, $this>
     */
    public function rates(): HasMany
    {
        return $this->hasMany(ProductChargeRate::class, 'product_charge_type_id');
    }

    /**
     * @return HasMany<ProductChargeAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(ProductChargeAssignment::class, 'product_charge_type_id');
    }
}

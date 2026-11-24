<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProductChargeRate extends BaseEnterpriseModel
{
    protected $table = 'product_charge_rates';

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:6',
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    /**
     * @return BelongsTo<ProductChargeType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(ProductChargeType::class, 'product_charge_type_id');
    }
}

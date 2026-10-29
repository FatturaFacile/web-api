<?php

declare(strict_types=1);

namespace Modules\Invoicing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SalesPriceList extends BaseEnterpriseModel
{
    protected $table = 'price_lists';

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesPriceListItem::class, 'price_list_id');
    }
}

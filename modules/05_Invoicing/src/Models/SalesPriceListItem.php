<?php

declare(strict_types=1);

namespace Modules\Invoicing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SalesPriceListItem extends BaseEnterpriseModel
{
    protected $table = 'price_list_items';

    protected function casts(): array
    {
        return [
            'price' => 'decimal:4',
            'min_quantity' => 'decimal:4',
        ];
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(SalesPriceList::class, 'price_list_id');
    }
}

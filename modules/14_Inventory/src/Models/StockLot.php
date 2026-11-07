<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class StockLot extends BaseEnterpriseModel
{
    protected $table = 'stock_lots';

    protected function casts(): array
    {
        return [
            'manufacturing_date' => 'date',
            'expiration_date' => 'date',
            'is_quarantined' => 'boolean',
            'received_at' => 'date',
            'certification_date' => 'date',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'supplier_id');
    }

    public function quants(): HasMany
    {
        return $this->hasMany(StockQuant::class, 'lot_id');
    }

    /**
     * The links to the lots this one was made from.
     */
    public function componentLinks(): HasMany
    {
        return $this->hasMany(StockLotLink::class, 'lot_id');
    }

    /**
     * The links to the lots made from this one.
     */
    public function derivedLinks(): HasMany
    {
        return $this->hasMany(StockLotLink::class, 'component_lot_id');
    }

    public function serialNumbers(): HasMany
    {
        return $this->hasMany(StockSerialNumber::class, 'lot_id');
    }
}

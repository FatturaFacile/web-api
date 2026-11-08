<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $expiration_date
 * @property Carbon|null $manufacturing_date
 * @property Carbon|null $received_at
 * @property Carbon|null $certification_date
 */
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

    /**
     * @return BelongsTo<StockItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'supplier_id');
    }

    /**
     * @return HasMany<StockQuant, $this>
     */
    public function quants(): HasMany
    {
        return $this->hasMany(StockQuant::class, 'lot_id');
    }

    /**
     * The links to the lots this one was made from.
     *
     * @return HasMany<StockLotLink, $this>
     */
    public function componentLinks(): HasMany
    {
        return $this->hasMany(StockLotLink::class, 'lot_id');
    }

    /**
     * The links to the lots made from this one.
     *
     * @return HasMany<StockLotLink, $this>
     */
    public function derivedLinks(): HasMany
    {
        return $this->hasMany(StockLotLink::class, 'component_lot_id');
    }

    /**
     * @return HasMany<StockSerialNumber, $this>
     */
    public function serialNumbers(): HasMany
    {
        return $this->hasMany(StockSerialNumber::class, 'lot_id');
    }
}

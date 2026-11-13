<?php

declare(strict_types=1);

namespace Modules\Workshop\Models;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The sale of a stock vehicle to a buyer, invoiced through the invoicing flow.
 */
final class VehicleSale extends WorkshopRecord
{
    protected $table = 'workshop_vehicle_sales';

    protected function casts(): array
    {
        return ['sale_date' => 'date', 'sale_price' => 'decimal:2', 'tax_rate' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<VehicleStockUnit, $this>
     */
    public function stockUnit(): BelongsTo
    {
        return $this->belongsTo(VehicleStockUnit::class, 'stock_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'buyer_contact_id');
    }
}

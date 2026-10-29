<?php

declare(strict_types=1);

namespace Modules\Invoicing\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Inventory\Models\Warehouse;

final class SalesDeliveryNote extends BaseEnterpriseModel
{
    protected $table = 'delivery_notes';

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'departure_time' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'packages_count' => 'integer',
            'gross_weight_kg' => 'decimal:2',
            'show_prices' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesDeliveryNoteItem::class, 'delivery_note_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SalesInvoice::class, 'delivery_note_id');
    }
}

<?php

declare(strict_types=1);

namespace Modules\Invoicing\Models;

use App\Models\Contact;
use App\Models\DeliveryNote;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Inventory\Models\Warehouse;
use Modules\Logistics\Concerns\HasAuditUsers;
use Modules\Logistics\Enums\TransportReason;
use Modules\Logistics\Models\DeliveryNoteInvoiceLink;

/**
 * Canonical Delivery Note (DDT) model. The legacy {@see DeliveryNote} maps to the same
 * `delivery_notes` table and is kept only as a compatibility layer for the legacy API / PDF engine.
 */
class SalesDeliveryNote extends BaseEnterpriseModel
{
    use HasAuditUsers;

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
            'issued_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'net_weight_kg' => 'decimal:2',
            'volume_m3' => 'decimal:3',
            'fiscal_year' => 'integer',
            'sequential_number' => 'integer',
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

    /**
     * @return HasMany<SalesDeliveryNoteItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SalesDeliveryNoteItem::class, 'delivery_note_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SalesInvoice::class, 'delivery_note_id');
    }

    public function originWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'origin_warehouse_id');
    }

    public function destinationWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    public function invoiceLinks(): HasMany
    {
        return $this->hasMany(DeliveryNoteInvoiceLink::class, 'delivery_note_id');
    }

    /**
     * Delivery notes that can be billed: goods sent on approval (conto visione) are not sold until accepted.
     *
     * @param  Builder<SalesDeliveryNote>  $query
     */
    public function scopeBillable(Builder $query): void
    {
        $query->where(static fn (Builder $q) => $q->whereNull('transport_reason_code')->orWhere('transport_reason_code', '!=', TransportReason::OnApproval->value));
    }

    public function isBillable(): bool
    {
        return $this->transport_reason_code !== TransportReason::OnApproval->value;
    }
}

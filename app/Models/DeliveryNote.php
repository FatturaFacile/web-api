<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Invoicing\Models\SalesDeliveryNote;

/**
 * @deprecated Compatibility layer for the legacy API and the PDF engine. The canonical model is
 *             {@see SalesDeliveryNote}; both map to the single `delivery_notes` table.
 */
class DeliveryNote extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'contact_id',
        'sales_order_id',
        'ddt_number',
        'date',
        'transport_reason',
        'goods_appearance',
        'packages_count',
        'gross_weight_kg',
        'carrier_name',
        'carrier_license_plate',
        'departure_time',
        'notes',
        'shipping_address',
        'courier',
        'show_prices',
        'status',
        'type',
        'transport_reason_code',
        'carriage_terms',
        'transport_by',
        'carrier_vat_number',
        'incoterms',
        'net_weight_kg',
        'volume_m3',
        'currency',
        'delivered_at',
        'recipient_signature_name',
        'tracking_number',
        'carrier_tracking_url',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'departure_time' => 'datetime',
            'gross_weight_kg' => 'decimal:2',
            'net_weight_kg' => 'decimal:2',
            'volume_m3' => 'decimal:3',
            'delivered_at' => 'datetime',
            'show_prices' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<SalesOrder, $this>
     */
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    /**
     * @return HasMany<DeliveryNoteItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(DeliveryNoteItem::class)->orderBy('id');
    }
}

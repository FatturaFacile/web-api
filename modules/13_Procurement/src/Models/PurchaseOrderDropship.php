<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The triangulation of a purchase order: final customer destination, custom DDT, supplier delivery proof and billing authorisation.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $purchase_order_id
 * @property string|null $sales_order_id
 * @property string $status
 * @property string $recipient_name
 * @property string $recipient_street
 * @property string|null $recipient_house_number
 * @property string $recipient_postal_code
 * @property string $recipient_city
 * @property string|null $recipient_region
 * @property string $recipient_country_code
 * @property string|null $recipient_phone
 * @property string|null $recipient_email
 * @property string|null $delivery_notes
 * @property string|null $ddt_number
 * @property Carbon|null $ddt_issued_at
 * @property array<string, mixed>|null $ddt_payload
 * @property string|null $supplier_shipment_reference
 * @property string|null $delivery_proof_reference
 * @property Carbon|null $delivered_at
 * @property bool $billing_authorized
 * @property Carbon|null $billing_authorized_at
 * @property string|null $billing_authorization_reference
 */
final class PurchaseOrderDropship extends BaseEnterpriseModel
{
    protected $table = 'purchase_order_dropships';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ddt_issued_at' => 'datetime',
            'ddt_payload' => 'array',
            'delivered_at' => 'datetime',
            'billing_authorized' => 'boolean',
            'billing_authorized_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    /**
     * @return HasMany<PurchaseOrderVirtualStockMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(PurchaseOrderVirtualStockMovement::class, 'purchase_order_dropship_id');
    }
}

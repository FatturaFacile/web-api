<?php

declare(strict_types=1);

namespace Modules\Invoicing\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $date
 * @property Carbon|null $due_date
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $requested_delivery_date
 * @property Carbon|null $confirmed_delivery_date
 * @property Carbon|null $credit_approved_at
 */
final class SalesOrder extends BaseEnterpriseModel
{
    protected $table = 'sales_orders';

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'due_date' => 'date',
            'confirmed_at' => 'datetime',
            'approved_at' => 'datetime',
            'requested_delivery_date' => 'date',
            'confirmed_delivery_date' => 'date',
            'credit_approved_at' => 'datetime',
            'allocated_percentage' => 'decimal:2',
            'delivered_percentage' => 'decimal:2',
            'invoiced_percentage' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total_net' => 'decimal:2',
            'total_tax' => 'decimal:2',
            'total_gross' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(SalesQuote::class, 'quote_id');
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(SalesPriceList::class, 'price_list_id');
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(CustomerAgreement::class, 'customer_agreement_id');
    }

    /**
     * @return HasMany<SalesOrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class, 'sales_order_id');
    }

    /**
     * @return HasMany<SalesDeliveryNote, $this>
     */
    public function deliveryNotes(): HasMany
    {
        return $this->hasMany(SalesDeliveryNote::class, 'sales_order_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SalesInvoice::class, 'sales_order_id');
    }

    public function holds(): HasMany
    {
        return $this->hasMany(SalesOrderHold::class, 'sales_order_id');
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(SalesCommission::class, 'sales_order_id');
    }

    /**
     * @return BelongsTo<BlanketAgreement, $this>
     */
    public function blanketAgreement(): BelongsTo
    {
        return $this->belongsTo(BlanketAgreement::class, 'blanket_agreement_id');
    }

    /**
     * @return HasMany<SalesOrderAuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(SalesOrderAuditLog::class, 'sales_order_id')->orderBy('created_at');
    }

    /**
     * @return HasMany<ReplenishmentRequest, $this>
     */
    public function replenishmentRequests(): HasMany
    {
        return $this->hasMany(ReplenishmentRequest::class, 'sales_order_id');
    }
}

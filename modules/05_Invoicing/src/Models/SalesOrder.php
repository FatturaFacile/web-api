<?php

declare(strict_types=1);

namespace Modules\Invoicing\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total_net' => 'decimal:2',
            'total_tax' => 'decimal:2',
            'total_gross' => 'decimal:2',
        ];
    }

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

    public function items(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class, 'sales_order_id');
    }

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
}

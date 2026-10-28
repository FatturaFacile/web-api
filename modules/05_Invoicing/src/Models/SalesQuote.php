<?php

declare(strict_types=1);

namespace Modules\Invoicing\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SalesQuote extends BaseEnterpriseModel
{
    protected $table = 'quotes';

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'valid_until' => 'date',
            'signed_at' => 'datetime',
            'approved_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total_net' => 'decimal:2',
            'total_tax' => 'decimal:2',
            'total_gross' => 'decimal:2',
            'revision_number' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(SalesPriceList::class, 'price_list_id');
    }

    public function discountPolicy(): BelongsTo
    {
        return $this->belongsTo(DiscountPolicy::class, 'discount_policy_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesQuoteItem::class, 'quote_id');
    }

    public function convertedOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'converted_to_order_id');
    }
}

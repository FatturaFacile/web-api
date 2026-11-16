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
use Modules\Invoicing\Models\SalesOrderHold;

class SalesOrder extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'contact_id',
        'quote_id',
        'order_number',
        'date',
        'due_date',
        'currency',
        'total_net',
        'total_tax',
        'total_gross',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'due_date' => 'date',
            'total_gross' => 'decimal:2',
            'total_net' => 'decimal:2',
            'total_tax' => 'decimal:2',
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
     * @return BelongsTo<Quote, $this>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /**
     * Calculated grid columns: the number of lines of the order.
     *
     * @return array<string, string>
     */
    public function gridColumns(): array
    {
        return [
            'items_count' => 'SELECT COUNT(*) FROM sales_order_items WHERE sales_order_items.sales_order_id = sales_orders.id AND sales_order_items.deleted_at IS NULL',
        ];
    }

    /**
     * @return HasMany<SalesOrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class)->orderBy('id');
    }

    /**
     * Credit holds (and other order holds) placed on the order, active or released; released through
     * `POST /invoicing/customer-credit/holds/{holdId}/release`.
     *
     * @return HasMany<SalesOrderHold, $this>
     */
    public function holds(): HasMany
    {
        return $this->hasMany(SalesOrderHold::class, 'sales_order_id');
    }

    /**
     * @return HasMany<DeliveryNote, $this>
     */
    public function deliveryNotes(): HasMany
    {
        return $this->hasMany(DeliveryNote::class);
    }
}

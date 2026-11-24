<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A sales return (RMA): goods a customer sends back, with the delivery note or invoice they came with. Authorised by a
 * sales manager, received in a warehouse (resellable goods go back into stock) and credited by a credit note on the
 * original invoice. See SalesReturnStatus for the life cycle.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $contact_id
 * @property string $return_number
 * @property CarbonInterface $date
 * @property string $status
 * @property string|null $sales_order_id
 * @property string|null $delivery_note_id
 * @property string|null $invoice_id
 * @property string|null $warehouse_id
 * @property string $reason
 * @property string|null $notes
 * @property string|null $credit_note_id
 * @property string|null $rejection_reason
 * @property CarbonInterface|null $approved_at
 * @property CarbonInterface|null $received_at
 * @property CarbonInterface|null $credited_at
 * @property CarbonInterface|null $created_at
 */
class SalesReturn extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    protected $fillable = [
        'contact_id',
        'return_number',
        'date',
        'status',
        'sales_order_id',
        'delivery_note_id',
        'invoice_id',
        'warehouse_id',
        'reason',
        'notes',
        'credit_note_id',
        'created_by',
        'approved_by',
        'approved_at',
        'received_at',
        'credited_at',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'approved_at' => 'datetime',
            'received_at' => 'datetime',
            'credited_at' => 'datetime',
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
     * @return BelongsTo<DeliveryNote, $this>
     */
    public function deliveryNote(): BelongsTo
    {
        return $this->belongsTo(DeliveryNote::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return HasMany<SalesReturnItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SalesReturnItem::class)->orderBy('id');
    }
}

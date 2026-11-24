<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Invoicing\Models\SalesDeliveryNote;

/**
 * Legacy alias of the canonical delivery note ({@see SalesDeliveryNote}). Same table, same columns, same scopes,
 * same behaviour: it only keeps the legacy relation names (`contact`, `salesOrder`, `items`) used by the old API,
 * the PDF engine and the policies registered on this class.
 *
 * @deprecated Use {@see SalesDeliveryNote} in new code.
 *
 * @property Carbon $date
 */
class DeliveryNote extends SalesDeliveryNote
{
    // Tenant isolation is inherited from the canonical model; the legacy trait is kept so the tenancy architecture rules still see it.
    use BelongsToTenant;

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

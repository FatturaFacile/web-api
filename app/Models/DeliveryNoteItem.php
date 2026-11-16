<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Invoicing\Models\SalesDeliveryNoteItem;

/**
 * Legacy alias of the canonical delivery note line ({@see SalesDeliveryNoteItem}).
 *
 * @deprecated Use {@see SalesDeliveryNoteItem} in new code.
 */
class DeliveryNoteItem extends SalesDeliveryNoteItem
{
    /**
     * @return BelongsTo<DeliveryNote, $this>
     */
    public function deliveryNote(): BelongsTo
    {
        return $this->belongsTo(DeliveryNote::class);
    }
}

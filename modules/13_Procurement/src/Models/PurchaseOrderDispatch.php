<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One transmission of a purchase order to the supplier (PDF, email, EDI or Peppol).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $purchase_order_id
 * @property string $channel
 * @property string $status
 * @property string|null $recipient
 * @property string|null $message_reference
 * @property Carbon $dispatched_at
 * @property string|null $dispatched_by
 * @property array<string, mixed>|null $metadata
 */
final class PurchaseOrderDispatch extends BaseEnterpriseModel
{
    protected $table = 'purchase_order_dispatches';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dispatched_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }
}

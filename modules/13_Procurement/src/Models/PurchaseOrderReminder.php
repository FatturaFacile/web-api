<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One reminder sent to a supplier for an overdue purchase order.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $purchase_order_id
 * @property int $level
 * @property string $channel
 * @property string $trigger
 * @property int $days_overdue
 * @property list<string>|null $overdue_line_ids
 * @property string|null $message_reference
 * @property Carbon $sent_at
 * @property string|null $sent_by
 */
final class PurchaseOrderReminder extends BaseEnterpriseModel
{
    protected $table = 'purchase_order_reminders';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'days_overdue' => 'integer',
            'overdue_line_ids' => 'array',
            'sent_at' => 'datetime',
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

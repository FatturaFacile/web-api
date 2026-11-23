<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Procurement\Models\PurchaseOrderReminder;

/**
 * A reminder sent to a supplier for an overdue purchase order.
 *
 * @mixin PurchaseOrderReminder
 */
final class PurchaseOrderReminderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_order_id' => $this->purchase_order_id,
            'level' => $this->level,
            'channel' => $this->channel,
            'trigger' => $this->trigger,
            'days_overdue' => $this->days_overdue,
            'overdue_line_ids' => $this->overdue_line_ids,
            'message_reference' => $this->message_reference,
            'sent_by' => $this->sent_by,
            'sent_at' => $this->sent_at->toIso8601String(),
        ];
    }
}

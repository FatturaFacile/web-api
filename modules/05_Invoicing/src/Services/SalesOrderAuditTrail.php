<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services;

use Illuminate\Support\Str;
use Modules\Invoicing\Models\SalesOrder;
use Modules\Invoicing\Models\SalesOrderAuditLog;

/**
 * Writes the append-only audit trail of a sales order (status changes, ATP recalculations, credit blocks and
 * overrides, backorder splits, replenishment triggers).
 */
final class SalesOrderAuditTrail
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(SalesOrder $order, string $event, array $payload = [], ?string $actorId = null, ?string $fromStatus = null, ?string $toStatus = null, ?string $itemId = null): SalesOrderAuditLog
    {
        return SalesOrderAuditLog::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $order->tenant_id,
            'sales_order_id' => (string) $order->id,
            'sales_order_item_id' => $itemId,
            'event' => $event,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_id' => $actorId !== null && Str::isUuid($actorId) ? $actorId : null,
            'payload' => $payload === [] ? null : $payload,
        ]);
    }
}

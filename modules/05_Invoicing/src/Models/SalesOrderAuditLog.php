<?php

declare(strict_types=1);

namespace Modules\Invoicing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only trail of a sales order: status transitions, ATP recalculations, credit blocks and overrides, backorder
 * splits, replenishment triggers.
 */
final class SalesOrderAuditLog extends BaseEnterpriseModel
{
    protected $table = 'sales_order_audit_logs';

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    /**
     * @return BelongsTo<SalesOrder, $this>
     */
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }
}

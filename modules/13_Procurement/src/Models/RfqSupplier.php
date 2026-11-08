<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A supplier invited to a request for quotation: invited, responded or declined.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $rfq_id
 * @property string $supplier_id
 * @property string $status
 * @property Carbon|null $invited_at
 * @property Carbon|null $responded_at
 */
final class RfqSupplier extends BaseEnterpriseModel
{
    protected $table = 'procurement_rfq_suppliers';

    protected function casts(): array
    {
        return [
            'invited_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }
}

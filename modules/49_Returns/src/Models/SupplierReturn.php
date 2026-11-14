<?php

declare(strict_types=1);

namespace Modules\Returns\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Procurement\Models\Supplier;

/**
 * A return to the supplier (RTV): goods sent back for credit. Shipping takes the goods out of the warehouse; the credit
 * the supplier owes is expected from creation and recorded when it arrives.
 *
 * draft -> shipped -> credited -> closed, shipped -> closed and draft -> cancelled.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $return_number
 * @property string $supplier_id
 * @property string|null $warehouse_id
 * @property string|null $rma_id
 * @property string $status
 * @property string|null $reason_code
 * @property string|null $carrier
 * @property string|null $tracking_number
 * @property string $currency
 * @property string $credit_expected
 * @property string|null $credit_received
 * @property Carbon|null $shipped_at
 * @property Carbon|null $credited_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $cancelled_at
 * @property string|null $notes
 * @property string|null $created_by
 */
final class SupplierReturn extends BaseEnterpriseModel
{
    public const string Draft = 'draft';

    public const string Shipped = 'shipped';

    public const string Credited = 'credited';

    public const string Closed = 'closed';

    public const string Cancelled = 'cancelled';

    /** @var list<string> */
    public const array Statuses = [self::Draft, self::Shipped, self::Credited, self::Closed, self::Cancelled];

    /**
     * Allowed moves of the state machine.
     *
     * @var array<string, list<string>>
     */
    public const array Transitions = [
        self::Draft => [self::Shipped, self::Cancelled],
        self::Shipped => [self::Credited, self::Closed],
        self::Credited => [self::Closed],
        self::Closed => [],
        self::Cancelled => [],
    ];

    protected $table = 'returns_supplier_returns';

    protected function casts(): array
    {
        return [
            'credit_expected' => 'decimal:2',
            'credit_received' => 'decimal:2',
            'shipped_at' => 'datetime',
            'credited_at' => 'datetime',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function canMoveTo(string $status): bool
    {
        return in_array($status, self::Transitions[$this->status] ?? [], true);
    }

    /**
     * @return HasMany<SupplierReturnLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SupplierReturnLine::class, 'supplier_return_id');
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}

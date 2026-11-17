<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Immutable hour-bank ledger entry of a maintenance contract. Hours are signed decimal strings:
 * deductions are negative, top-ups positive. Entries are never updated or deleted.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $service_contract_id
 * @property string $type
 * @property string $hours
 * @property string $balance_after
 * @property string|null $work_order_id
 * @property string|null $ticket_id
 * @property string|null $equipment_passport_id
 * @property string|null $technician_name
 * @property string|null $reference
 * @property string|null $description
 * @property Carbon|null $occurred_at
 */
final class ContractHourTransaction extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const TYPE_DEDUCTION = 'deduction';

    public const TYPE_TOP_UP = 'top_up';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const UPDATED_AT = null;

    protected $table = 'esm_contract_hour_transactions';

    protected $guarded = [];

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('Hour-bank ledger entries are immutable.');
        });
        self::deleting(static function (): never {
            throw new LogicException('Hour-bank ledger entries are immutable.');
        });
    }

    protected function casts(): array
    {
        return [
            'hours' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ServiceContract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(ServiceContract::class, 'service_contract_id');
    }

    /**
     * @return BelongsTo<SerializedEquipmentPassport, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(SerializedEquipmentPassport::class, 'equipment_passport_id');
    }
}

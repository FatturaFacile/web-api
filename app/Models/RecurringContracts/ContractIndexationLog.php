<?php

declare(strict_types=1);

namespace App\Models\RecurringContracts;

use App\Models\Concerns\BelongsToTenant;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $recurring_contract_id
 * @property string $clause_id
 * @property string $period_from
 * @property string $period_to
 * @property string $old_index_value
 * @property string $new_index_value
 * @property string $raw_delta_percent
 * @property string $applied_delta_percent
 * @property string $old_recurring_total
 * @property string $new_recurring_total
 * @property CarbonInterface|null $applied_at
 * @property string|null $applied_by_user_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class ContractIndexationLog extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $table = 'contract_indexation_logs';

    protected $fillable = [
        'recurring_contract_id',
        'clause_id',
        'period_from',
        'period_to',
        'old_index_value',
        'new_index_value',
        'raw_delta_percent',
        'applied_delta_percent',
        'old_recurring_total',
        'new_recurring_total',
        'applied_at',
        'applied_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'old_index_value' => 'decimal:4',
            'new_index_value' => 'decimal:4',
            'raw_delta_percent' => 'decimal:4',
            'applied_delta_percent' => 'decimal:4',
            'old_recurring_total' => 'decimal:4',
            'new_recurring_total' => 'decimal:4',
            'applied_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<RecurringContract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(RecurringContract::class, 'recurring_contract_id');
    }

    /**
     * @return BelongsTo<ContractIndexationClause, $this>
     */
    public function clause(): BelongsTo
    {
        return $this->belongsTo(ContractIndexationClause::class, 'clause_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function appliedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by_user_id');
    }
}

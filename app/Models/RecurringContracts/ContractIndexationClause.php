<?php

declare(strict_types=1);

namespace App\Models\RecurringContracts;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $recurring_contract_id
 * @property string $index_type
 * @property string $base_index_value
 * @property string $base_index_period
 * @property int $adjustment_frequency_months
 * @property string $pass_through_percent
 * @property string|null $floor_percent
 * @property string|null $cap_percent
 * @property CarbonInterface|null $next_adjustment_date
 * @property CarbonInterface|null $last_adjusted_at
 * @property bool $is_active
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class ContractIndexationClause extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $table = 'contract_indexation_clauses';

    protected $fillable = [
        'recurring_contract_id',
        'index_type',
        'base_index_value',
        'base_index_period',
        'adjustment_frequency_months',
        'pass_through_percent',
        'floor_percent',
        'cap_percent',
        'next_adjustment_date',
        'last_adjusted_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'base_index_value' => 'decimal:4',
            'adjustment_frequency_months' => 'integer',
            'pass_through_percent' => 'decimal:2',
            'floor_percent' => 'decimal:2',
            'cap_percent' => 'decimal:2',
            'next_adjustment_date' => 'date',
            'last_adjusted_at' => 'datetime',
            'is_active' => 'boolean',
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
     * @return HasMany<ContractIndexationLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(ContractIndexationLog::class, 'clause_id')->orderByDesc('applied_at');
    }
}

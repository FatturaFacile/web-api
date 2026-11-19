<?php

declare(strict_types=1);

namespace App\Models\RecurringContracts;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $run_type
 * @property CarbonInterface|null $target_date
 * @property string $status
 * @property int $contracts_processed
 * @property int $invoices_generated
 * @property string $total_amount
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $finished_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class RecurringBillingRun extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $table = 'recurring_billing_runs';

    protected $fillable = [
        'run_type',
        'target_date',
        'status',
        'contracts_processed',
        'invoices_generated',
        'total_amount',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'target_date' => 'date',
            'contracts_processed' => 'integer',
            'invoices_generated' => 'integer',
            'total_amount' => 'decimal:4',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<RecurringBillingRunItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(RecurringBillingRunItem::class, 'run_id');
    }
}

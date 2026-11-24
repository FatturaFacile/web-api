<?php

declare(strict_types=1);

namespace App\Models\RecurringContracts;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Invoice;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $run_id
 * @property string $recurring_contract_id
 * @property CarbonInterface|null $billing_period_start
 * @property CarbonInterface|null $billing_period_end
 * @property string $idempotency_key
 * @property string|null $invoice_id
 * @property string $status
 * @property string|null $error_message
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class RecurringBillingRunItem extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $table = 'recurring_billing_run_items';

    protected $fillable = [
        'run_id',
        'recurring_contract_id',
        'billing_period_start',
        'billing_period_end',
        'idempotency_key',
        'invoice_id',
        'status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'billing_period_start' => 'date',
            'billing_period_end' => 'date',
        ];
    }

    /**
     * @return BelongsTo<RecurringBillingRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(RecurringBillingRun::class, 'run_id');
    }

    /**
     * @return BelongsTo<RecurringContract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(RecurringContract::class, 'recurring_contract_id');
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }
}

<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\AccountsPayable\Enums\PaymentRunStatus;

/**
 * A payment run (distinta di pagamento): the open items proposed for payment on one execution date, the credit notes
 * offset against them, the Skonto taken and the transfers grouped by creditor IBAN. It needs the approval of a second
 * person (the CFO) before it can be executed.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $run_number
 * @property string $status
 * @property string $currency
 * @property Carbon $cutoff_date
 * @property Carbon $execution_date
 * @property string|null $bank_account_id
 * @property bool $include_skonto
 * @property bool $net_credit_notes
 * @property list<string>|null $supplier_ids
 * @property string $debit_total
 * @property string $credit_netted_total
 * @property string $skonto_total
 * @property string $net_total
 * @property int $items_count
 * @property int $transfers_count
 * @property list<array<string, mixed>>|null $excluded
 * @property string|null $notes
 * @property string|null $submitted_by
 * @property Carbon|null $submitted_at
 * @property string|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $rejected_by
 * @property Carbon|null $rejected_at
 * @property string|null $rejection_reason
 * @property string|null $executed_by
 * @property Carbon|null $executed_at
 * @property string|null $created_by
 */
final class ApPaymentRun extends BaseEnterpriseModel
{
    protected $table = 'ap_payment_runs';

    protected function casts(): array
    {
        return [
            'cutoff_date' => 'date',
            'execution_date' => 'date',
            'include_skonto' => 'boolean',
            'net_credit_notes' => 'boolean',
            'supplier_ids' => 'array',
            'excluded' => 'array',
            'debit_total' => 'decimal:2',
            'credit_netted_total' => 'decimal:2',
            'skonto_total' => 'decimal:2',
            'net_total' => 'decimal:2',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    public function statusEnum(): PaymentRunStatus
    {
        return PaymentRunStatus::from((string) $this->status);
    }

    /**
     * @return HasMany<ApPaymentRunItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ApPaymentRunItem::class, 'payment_run_id')->orderBy('created_at')->orderBy('id');
    }

    /**
     * @return HasMany<ApPaymentRunTransfer, $this>
     */
    public function transfers(): HasMany
    {
        return $this->hasMany(ApPaymentRunTransfer::class, 'payment_run_id')->orderBy('created_at')->orderBy('id');
    }

    /**
     * @return HasMany<ApPaymentRunOffset, $this>
     */
    public function offsets(): HasMany
    {
        return $this->hasMany(ApPaymentRunOffset::class, 'payment_run_id')->orderBy('created_at')->orderBy('id');
    }
}

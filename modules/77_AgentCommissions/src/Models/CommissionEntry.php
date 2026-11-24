<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Invoicing\Models\SalesInvoice;

/**
 * The commission of an agent on one invoice line. `commission_amount` is what the rule earned at issuance,
 * `credited_amount` what credit notes took back, `eligible_amount` what may be paid now (the whole effective
 * commission on the invoiced basis, the bank-reconciled share on the collected basis) and `settled_amount` what
 * statements already took.
 *
 * @property string $agent_profile_id
 * @property string $role
 * @property string $invoice_id
 * @property string $invoice_item_id
 * @property string|null $source_entry_id
 * @property string|null $rule_id
 * @property string $accrual_basis
 * @property string $currency
 * @property Carbon $accrued_on
 * @property string $base_amount
 * @property string|null $rate
 * @property string $commission_amount
 * @property string $credited_amount
 * @property string $eligible_amount
 * @property string $settled_amount
 * @property string $collected_ratio
 * @property string $reconciled_ratio
 * @property string $status
 * @property-read AgentProfile|null $agent
 * @property-read SalesInvoice|null $invoice
 * @property-read Collection<int, EntryMovement> $movements
 */
final class CommissionEntry extends BaseEnterpriseModel
{
    protected $table = 'agc_entries';

    protected function casts(): array
    {
        return [
            'accrued_on' => 'date',
            'base_amount' => 'decimal:2',
            'rate' => 'decimal:4',
            'commission_amount' => 'decimal:2',
            'credited_amount' => 'decimal:2',
            'eligible_amount' => 'decimal:2',
            'settled_amount' => 'decimal:2',
            'collected_ratio' => 'decimal:6',
            'reconciled_ratio' => 'decimal:6',
        ];
    }

    /**
     * @return BelongsTo<AgentProfile, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(AgentProfile::class, 'agent_profile_id');
    }

    /**
     * @return BelongsTo<SalesInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'invoice_id');
    }

    /**
     * @return HasMany<EntryMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(EntryMovement::class, 'entry_id')->orderBy('occurred_on')->orderBy('id');
    }
}

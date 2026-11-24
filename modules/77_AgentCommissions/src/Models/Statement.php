<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A commission statement (estratto conto provvigionale) of an agent for a period, with the fiscal breakdown and the
 * liquidation lifecycle: draft, approved, invoiced, paid, void.
 *
 * @property string $agent_profile_id
 * @property string $number
 * @property string $period_type
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property string $status
 * @property string $currency
 * @property string $base_total
 * @property string $own_commission_total
 * @property string $override_commission_total
 * @property string $clawback_total
 * @property string $gross_commission
 * @property string $enasarco_base
 * @property string $enasarco_agent
 * @property string $enasarco_company
 * @property string $firr_accrual
 * @property string $withholding_base
 * @property string $withholding_amount
 * @property string $vat_amount
 * @property string $net_payable
 * @property string $blocked_amount
 * @property bool $massimale_reached
 * @property array<string, mixed>|null $fiscal_snapshot
 * @property string|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $self_billing_number
 * @property Carbon|null $self_billing_date
 * @property array<string, mixed>|null $self_billing_payload
 * @property Carbon|null $paid_at
 * @property string|null $payment_reference
 * @property Carbon|null $sent_at
 * @property string|null $sent_to
 * @property int $sent_count
 * @property-read AgentProfile|null $agent
 * @property-read Collection<int, StatementLine> $lines
 */
final class Statement extends BaseEnterpriseModel
{
    protected $table = 'agc_statements';

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'base_total' => 'decimal:2',
            'own_commission_total' => 'decimal:2',
            'override_commission_total' => 'decimal:2',
            'clawback_total' => 'decimal:2',
            'gross_commission' => 'decimal:2',
            'enasarco_base' => 'decimal:2',
            'enasarco_agent' => 'decimal:2',
            'enasarco_company' => 'decimal:2',
            'firr_accrual' => 'decimal:2',
            'withholding_base' => 'decimal:2',
            'withholding_amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'net_payable' => 'decimal:2',
            'blocked_amount' => 'decimal:2',
            'massimale_reached' => 'boolean',
            'fiscal_snapshot' => 'array',
            'self_billing_date' => 'date',
            'self_billing_payload' => 'array',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'sent_at' => 'datetime',
            'sent_count' => 'integer',
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
     * @return HasMany<StatementLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StatementLine::class, 'statement_id')->orderBy('invoice_date');
    }
}

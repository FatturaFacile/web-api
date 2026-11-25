<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\GdoTradeTerms\Domain\Enums\SettlementKind;
use Modules\GdoTradeTerms\Domain\Enums\SettlementStatus;
use Modules\GdoTradeTerms\Domain\Enums\TaxTreatment;

/**
 * A settlement run (RebateSettlementRun): the accrued amounts of a contract against what was actually earned, settled with
 * a credit note (target rebates, flyer price reductions) and reconciled with the promotional invoice of the retailer.
 *
 * @property string $contract_id
 * @property string $code
 * @property SettlementKind $kind
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property SettlementStatus $status
 * @property TaxTreatment $tax_treatment
 * @property string $currency
 * @property string $accrued_total
 * @property string $earned_total
 * @property string $variance_total
 * @property string $credit_note_amount
 * @property string $promo_amount
 * @property string|null $tax_rate
 * @property string|null $credit_note_id
 * @property string|null $claim_invoice_number
 * @property Carbon|null $claim_invoice_date
 * @property string|null $claim_net
 * @property string|null $claim_tax
 * @property string|null $claim_variance
 * @property string|null $dispute_reason
 * @property string|null $release_journal_entry_id
 * @property string|null $set_off_journal_entry_id
 * @property Carbon|null $settled_on
 * @property string|null $notes
 */
final class GdoSettlement extends BaseEnterpriseModel
{
    protected $table = 'gdt_settlements';

    protected function casts(): array
    {
        return [
            'kind' => SettlementKind::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'status' => SettlementStatus::class,
            'tax_treatment' => TaxTreatment::class,
            'accrued_total' => 'decimal:4',
            'earned_total' => 'decimal:4',
            'variance_total' => 'decimal:4',
            'credit_note_amount' => 'decimal:4',
            'promo_amount' => 'decimal:4',
            'tax_rate' => 'decimal:4',
            'claim_invoice_date' => 'date',
            'claim_net' => 'decimal:4',
            'claim_tax' => 'decimal:4',
            'claim_variance' => 'decimal:4',
            'settled_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<GdoContract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(GdoContract::class, 'contract_id');
    }

    /**
     * @return HasMany<GdoSettlementLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(GdoSettlementLine::class, 'settlement_id')->orderBy('treatment')->orderBy('source_label');
    }
}

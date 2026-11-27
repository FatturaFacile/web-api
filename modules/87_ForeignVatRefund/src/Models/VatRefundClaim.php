<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\ForeignVatRefund\Domain\Enums\ClaimStatus;
use Modules\ForeignVatRefund\Domain\Enums\PeriodType;
use Modules\ForeignVatRefund\Domain\Enums\RegimeType;

/**
 * A refund application (dossier) to the tax authority of one country for one period.
 *
 * @property string $claim_number
 * @property string $home_country_code
 * @property string $target_country_code
 * @property RegimeType $regime_type
 * @property PeriodType $period_type
 * @property int $fiscal_year
 * @property Carbon $period_start_date
 * @property Carbon $period_end_date
 * @property Carbon $statutory_deadline_date
 * @property string $currency_code
 * @property string $total_requested_amount
 * @property string $total_approved_amount
 * @property string $total_rejected_amount
 * @property string $total_refunded_amount
 * @property string $carried_base_amount
 * @property string|null $refund_exchange_rate
 * @property string|null $fiscal_representative_mandate_id
 * @property ClaimStatus $status
 * @property Carbon|null $submission_date
 * @property string|null $protocol_number
 * @property string|null $generated_xml_path
 * @property string|null $generated_pdf_dossier_path
 * @property string|null $zip_bundle_path
 * @property string|null $bank_account_id
 * @property string|null $rejection_journal_entry_id
 * @property string|null $reconciliation_journal_entry_id
 * @property Carbon|null $refunded_on
 * @property string|null $notes
 * @property string|null $created_by
 */
final class VatRefundClaim extends BaseEnterpriseModel
{
    protected $table = 'vat_refund_claims';

    protected function casts(): array
    {
        return [
            'regime_type' => RegimeType::class,
            'period_type' => PeriodType::class,
            'status' => ClaimStatus::class,
            'fiscal_year' => 'integer',
            'period_start_date' => 'date',
            'period_end_date' => 'date',
            'statutory_deadline_date' => 'date',
            'total_requested_amount' => 'decimal:2',
            'total_approved_amount' => 'decimal:2',
            'total_rejected_amount' => 'decimal:2',
            'total_refunded_amount' => 'decimal:2',
            'carried_base_amount' => 'decimal:2',
            'refund_exchange_rate' => 'decimal:6',
            'submission_date' => 'datetime',
            'refunded_on' => 'date',
        ];
    }

    /**
     * @return HasMany<ForeignVatLedgerEntry, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ForeignVatLedgerEntry::class, 'vat_refund_claim_id')->orderBy('document_date');
    }

    /**
     * @return HasMany<VatRefundClaimEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(VatRefundClaimEvent::class, 'vat_refund_claim_id')->orderBy('created_at');
    }

    /**
     * @return BelongsTo<FiscalRepresentativeMandate, $this>
     */
    public function mandate(): BelongsTo
    {
        return $this->belongsTo(FiscalRepresentativeMandate::class, 'fiscal_representative_mandate_id');
    }
}

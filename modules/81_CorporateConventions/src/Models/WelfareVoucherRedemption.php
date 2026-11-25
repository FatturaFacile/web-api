<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Models;

use Carbon\CarbonInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\CorporateConventions\Domain\Enums\ReimbursementStatus;
use Modules\CorporateConventions\Domain\Enums\SourceDocumentType;
use Modules\CorporateConventions\Domain\Enums\WelfareCategory;
use Modules\CorporateConventions\Domain\Enums\WelfareJurisdiction;
use Modules\CorporateConventions\Domain\Enums\WelfareProvider;
use Modules\CorporateConventions\Domain\Support\Dec;
use Modules\CorporateConventions\Exceptions\ConventionException;

/**
 * Aggregate root of a welfare voucher paid at the till (IT Art. 51 TUIR, CH Reka / Lunch-Check): the face value, the merchant fee
 * the provider withholds, the net the merchant expects and the settlement lifecycle: pending → submitted → reconciled | rejected.
 *
 * @property SourceDocumentType $source_document_type
 * @property string $source_document_id
 * @property string|null $enrollment_id
 * @property WelfareJurisdiction $jurisdiction
 * @property WelfareProvider $provider
 * @property WelfareCategory $welfare_category
 * @property string $voucher_code_masked
 * @property string $voucher_barcode_hash
 * @property string $currency
 * @property string $gross_voucher_amount
 * @property string $merchant_fee_percentage
 * @property string $merchant_fee_amount
 * @property string $net_expected_reimbursement
 * @property ReimbursementStatus $reimbursement_status
 * @property string|null $settlement_batch_reference
 * @property string|null $provider_self_invoice_number
 * @property string|null $settled_amount
 * @property Carbon|null $reconciled_at
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property string|null $deleted_by
 */
final class WelfareVoucherRedemption extends BaseEnterpriseModel
{
    protected $table = 'pos_welfare_voucher_redemptions';

    protected function casts(): array
    {
        return [
            'source_document_type' => SourceDocumentType::class,
            'jurisdiction' => WelfareJurisdiction::class,
            'provider' => WelfareProvider::class,
            'welfare_category' => WelfareCategory::class,
            'reimbursement_status' => ReimbursementStatus::class,
            'gross_voucher_amount' => 'decimal:2',
            'merchant_fee_percentage' => 'decimal:4',
            'merchant_fee_amount' => 'decimal:2',
            'net_expected_reimbursement' => 'decimal:2',
            'settled_amount' => 'decimal:2',
            'reconciled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ConventionBeneficiaryEnrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(ConventionBeneficiaryEnrollment::class, 'enrollment_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->reimbursement_status, [ReimbursementStatus::PendingSettlement, ReimbursementStatus::SubmittedToProvider], true);
    }

    public function submit(string $batchReference): void
    {
        if ($this->reimbursement_status !== ReimbursementStatus::PendingSettlement) {
            throw new ConventionException('welfare_not_pending', 409);
        }

        $this->reimbursement_status = ReimbursementStatus::SubmittedToProvider;
        $this->settlement_batch_reference = $batchReference;
    }

    /**
     * Records what the provider settled. Within the tolerance of the expected net the voucher is reconciled; a different amount
     * rejects it (to be disputed with the provider). Returns whether it was reconciled.
     */
    public function settle(string $settledAmount, string $batchReference, ?string $selfInvoiceNumber, string $tolerance, CarbonInterface $now): bool
    {
        if (! $this->isOpen()) {
            throw new ConventionException('welfare_not_open', 409);
        }

        $difference = Dec::sub($settledAmount, $this->net_expected_reimbursement);
        $matches = Dec::cmp($difference, $tolerance) <= 0 && Dec::cmp($difference, '-'.ltrim($tolerance, '-')) >= 0;

        $this->settled_amount = Dec::round($settledAmount);
        $this->settlement_batch_reference = $batchReference;
        $this->provider_self_invoice_number = $selfInvoiceNumber;
        $this->reimbursement_status = $matches ? ReimbursementStatus::Reconciled : ReimbursementStatus::Rejected;
        $this->reconciled_at = $matches ? Carbon::instance($now) : null;

        return $matches;
    }
}

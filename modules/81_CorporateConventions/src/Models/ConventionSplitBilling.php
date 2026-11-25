<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\CorporateConventions\Domain\Enums\SourceDocumentType;
use Modules\CorporateConventions\Domain\Enums\SplitBillingStatus;
use Modules\CorporateConventions\Exceptions\ConventionException;

/**
 * One purchase split between the employee (who pays the receipt) and the employer (whose quota is billed to the partner).
 *
 * @property string $convention_id
 * @property string $enrollment_id
 * @property SourceDocumentType $source_document_type
 * @property string $source_document_id
 * @property Carbon $transaction_date
 * @property string $currency
 * @property string $total_gross_amount
 * @property string $convention_discount_amount
 * @property string $employer_subsidy_amount
 * @property string $employee_paid_amount
 * @property string $cost_amount
 * @property string|null $employee_document_id
 * @property string|null $b2b_consolidated_invoice_id
 * @property SplitBillingStatus $billing_status
 * @property string|null $notes
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property string|null $deleted_by
 */
final class ConventionSplitBilling extends BaseEnterpriseModel
{
    protected $table = 'sales_convention_split_billings';

    protected function casts(): array
    {
        return [
            'source_document_type' => SourceDocumentType::class,
            'billing_status' => SplitBillingStatus::class,
            'transaction_date' => 'date',
            'total_gross_amount' => 'decimal:2',
            'convention_discount_amount' => 'decimal:2',
            'employer_subsidy_amount' => 'decimal:2',
            'employee_paid_amount' => 'decimal:2',
            'cost_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<CorporatePartnerConvention, $this>
     */
    public function convention(): BelongsTo
    {
        return $this->belongsTo(CorporatePartnerConvention::class, 'convention_id');
    }

    /**
     * @return BelongsTo<ConventionBeneficiaryEnrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(ConventionBeneficiaryEnrollment::class, 'enrollment_id');
    }

    public function markInvoiced(string $invoiceId): void
    {
        if ($this->billing_status !== SplitBillingStatus::PendingConsolidation) {
            throw new ConventionException('split_billing_not_pending', 409);
        }

        $this->billing_status = SplitBillingStatus::Invoiced;
        $this->b2b_consolidated_invoice_id = $invoiceId;
    }
}

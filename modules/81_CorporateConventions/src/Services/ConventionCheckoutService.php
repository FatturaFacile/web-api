<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\CorporateConventions\Domain\Enums\SourceDocumentType;
use Modules\CorporateConventions\Domain\Enums\SplitBillingStatus;
use Modules\CorporateConventions\Domain\Support\Dec;
use Modules\CorporateConventions\Domain\ValueObjects\PricingResult;
use Modules\CorporateConventions\Exceptions\ConventionException;
use Modules\CorporateConventions\Models\ConventionBeneficiaryEnrollment;
use Modules\CorporateConventions\Models\ConventionSplitBilling;
use Modules\CorporateConventions\Models\CorporatePartnerConvention;

/**
 * Commits a convention purchase atomically: the discount and the employer subsidy are consumed from the annual caps of the
 * beneficiary and the split between employee and employer is recorded, one row per source document.
 */
final class ConventionCheckoutService
{
    public function __construct(private readonly ConventionPricingService $pricing) {}

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{split_billing: ConventionSplitBilling, pricing: PricingResult}
     */
    public function apply(
        CorporatePartnerConvention $convention,
        ConventionBeneficiaryEnrollment $enrollment,
        array $lines,
        SourceDocumentType $documentType,
        string $documentId,
        ?string $employeeDocumentId,
        ?CarbonImmutable $transactionDate,
        ?string $currency,
        ?string $userId,
    ): array {
        return DB::transaction(function () use ($convention, $enrollment, $lines, $documentType, $documentId, $employeeDocumentId, $transactionDate, $currency, $userId): array {
            // The balance is read under a row lock: two tills serving the same card cannot both spend the same cap.
            $locked = ConventionBeneficiaryEnrollment::query()->whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
            $locked->setRelation('convention', $convention);

            $duplicate = ConventionSplitBilling::query()
                ->where('source_document_type', $documentType->value)->where('source_document_id', $documentId)->exists();

            if ($duplicate) {
                throw new ConventionException('checkout_already_applied', 409);
            }

            $result = $this->pricing->price($convention, $locked, $lines, $currency);
            $date = $transactionDate ?? CarbonImmutable::now();
            $hasSubsidy = Dec::cmp($result->employerSubsidy, '0') > 0;

            $billing = ConventionSplitBilling::query()->create([
                'tenant_id' => $convention->tenant_id,
                'convention_id' => $convention->id,
                'enrollment_id' => $locked->id,
                'source_document_type' => $documentType,
                'source_document_id' => $documentId,
                'transaction_date' => $date->toDateString(),
                'currency' => $convention->currency,
                'total_gross_amount' => $result->totalGross,
                'convention_discount_amount' => $result->conventionDiscount,
                'employer_subsidy_amount' => $result->employerSubsidy,
                'employee_paid_amount' => $result->employeePaid,
                'cost_amount' => $result->totalCost,
                'employee_document_id' => $employeeDocumentId,
                'billing_status' => $hasSubsidy ? SplitBillingStatus::PendingConsolidation : SplitBillingStatus::Paid,
                'notes' => $hasSubsidy ? null : 'no_employer_quota',
                'created_by' => $userId,
            ]);

            $locked->registerUsage($result->cappedSpend, $result->employerSubsidy, (int) now()->year);
            $locked->updated_by = $userId;
            $locked->save();

            return ['split_billing' => $billing, 'pricing' => $result];
        });
    }
}

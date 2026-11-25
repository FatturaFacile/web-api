<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Strategies;

use Modules\ExpenseManagement\Contracts\ExpenseFiscalComplianceStrategyInterface;
use Modules\ExpenseManagement\Domain\Enums\AllowanceMethod;
use Modules\ExpenseManagement\Domain\Enums\CalculationSource;
use Modules\ExpenseManagement\Domain\Enums\DestinationType;
use Modules\ExpenseManagement\Domain\ValueObjects\LineAssessment;
use Modules\ExpenseManagement\Domain\ValueObjects\LineFacts;
use Modules\ExpenseManagement\Domain\ValueObjects\MileageRateRequest;
use Modules\ExpenseManagement\Domain\ValueObjects\PerDiemFacts;
use Modules\ExpenseManagement\Domain\ValueObjects\PerDiemResult;
use Modules\ExpenseManagement\Domain\ValueObjects\ResolvedMileageRate;
use Modules\ExpenseManagement\Services\VehicleRateRepository;
use Modules\ExpenseManagement\Support\Decimal;

/**
 * Swiss rules: expenses follow the expense regulation (Spesenreglement) approved by the cantonal tax authority, with
 * lump-sum allowances or itemised expenses. Mileage is CHF 0.70/km unless the regulation (policy) sets another rate or the
 * TCS table is requested. VAT (MWST/TVA/IVA) is recoverable on qualifying receipts: up to CHF 400 a receipt without the
 * recipient is enough, above it the supplier UID is required.
 */
final class SwissSpesenStrategy implements ExpenseFiscalComplianceStrategyInterface
{
    public const string DefaultMileageRate = '0.7000';

    public const string DomesticDailyLimit = '120.00';

    public const string InternationalDailyLimit = '180.00';

    public const string ReceiptWithoutRecipientCeiling = '400.00';

    public function __construct(private readonly VehicleRateRepository $rates) {}

    public function jurisdiction(): string
    {
        return 'CH';
    }

    public function assessLine(LineFacts $facts): LineAssessment
    {
        $violations = [];
        $recoverable = '0.00';

        if (Decimal::isPositive($facts->vatAmount)) {
            $qualifies = $facts->categoryVatRecoverable
                && ($facts->merchantTaxId !== null || Decimal::cmp($facts->amountInBase, self::ReceiptWithoutRecipientCeiling) <= 0);

            if ($qualifies) {
                $recoverable = $facts->vatAmount;
            } else {
                $violations[] = ['code' => 'vat_not_recoverable', 'severity' => 'warning', 'params' => []];
            }
        }

        return new LineAssessment($recoverable, $facts->declaredTraceable, false, $violations);
    }

    public function resolveMileageRate(MileageRateRequest $request): ResolvedMileageRate
    {
        if ($request->requestedSource === CalculationSource::ChTcs) {
            $row = $this->rates->find(CalculationSource::ChTcs->value, $request->vehicleClassCode, $request->onDate, $request->annualKmBracket);

            if ($row !== null) {
                return new ResolvedMileageRate(CalculationSource::ChTcs, Decimal::normalize($row->rate_per_km, 4), $row->currency, $row->rate_period, $row->valid_from->toDateString());
            }
        }

        return new ResolvedMileageRate(CalculationSource::ChSpesenreglement, Decimal::normalize($request->policyRatePerKm ?? self::DefaultMileageRate, 4), 'CHF');
    }

    public function calculatePerDiem(PerDiemFacts $facts): PerDiemResult
    {
        $base = $facts->policyDailyLimit ?? ($facts->destinationType === DestinationType::International ? self::InternationalDailyLimit : self::DomesticDailyLimit);
        $base = $facts->destinationType === DestinationType::Municipality ? '0.00' : Decimal::normalize($base);
        $effective = $base;

        if ($facts->method === AllowanceMethod::Mixed) {
            $effective = PerDiemReductions::apply($base, $facts->mealsReimbursed, $facts->lodgingReimbursed);
        }

        return PerDiemReductions::result($facts, $base, $effective, $facts->method === AllowanceMethod::Analytic ? '0.00' : $effective);
    }

    public function noticeCodes(): array
    {
        return ['ch_spesenreglement', 'ch_tcs_mileage'];
    }
}

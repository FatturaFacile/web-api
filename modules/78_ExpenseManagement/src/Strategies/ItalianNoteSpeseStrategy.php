<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Strategies;

use Modules\ExpenseManagement\Contracts\ExpenseFiscalComplianceStrategyInterface;
use Modules\ExpenseManagement\Domain\Enums\AllowanceMethod;
use Modules\ExpenseManagement\Domain\Enums\CalculationSource;
use Modules\ExpenseManagement\Domain\Enums\DestinationType;
use Modules\ExpenseManagement\Domain\Enums\PaymentMethod;
use Modules\ExpenseManagement\Domain\ValueObjects\LineAssessment;
use Modules\ExpenseManagement\Domain\ValueObjects\LineFacts;
use Modules\ExpenseManagement\Domain\ValueObjects\MileageRateRequest;
use Modules\ExpenseManagement\Domain\ValueObjects\PerDiemFacts;
use Modules\ExpenseManagement\Domain\ValueObjects\PerDiemResult;
use Modules\ExpenseManagement\Domain\ValueObjects\ResolvedMileageRate;
use Modules\ExpenseManagement\Exceptions\ExpenseException;
use Modules\ExpenseManagement\Services\VehicleRateRepository;
use Modules\ExpenseManagement\Support\Decimal;

/**
 * Italian rules (Art. 51, comma 5 TUIR). Transfer allowance outside the municipality: flat-rate and mixed regimes are
 * tax free up to EUR 46.48 a day in Italy and EUR 77.47 abroad (reduced by a third for meals and by a third for lodging
 * provided or reimbursed analytically); the analytic regime reimburses documented expenses without income, plus up to
 * EUR 15.49 / 25.82 a day for expenses that cannot be documented. Inside the municipality the allowance is always taxable.
 * Meals, lodging and taxi paid in cash are not traceable: the cost is not deductible, the VAT is not recoverable and the
 * reimbursement is a taxable benefit. Mileage follows the ACI tables.
 */
final class ItalianNoteSpeseStrategy implements ExpenseFiscalComplianceStrategyInterface
{
    public const string DomesticLimit = '46.48';

    public const string AbroadLimit = '77.47';

    public const string AnalyticDomesticLimit = '15.49';

    public const string AnalyticAbroadLimit = '25.82';

    public function __construct(private readonly VehicleRateRepository $rates) {}

    public function jurisdiction(): string
    {
        return 'IT';
    }

    public function assessLine(LineFacts $facts): LineAssessment
    {
        $violations = [];
        $traceable = $facts->paymentMethod === PaymentMethod::CorporateCard ? true : $facts->declaredTraceable;
        $taxable = false;

        if (! $traceable && ($facts->isMeal || $facts->isLodging || $facts->isTransport)) {
            $taxable = true;
            $violations[] = [
                'code' => 'non_traceable_payment',
                'severity' => $facts->blockNonTraceable ? 'blocking' : 'warning',
                'params' => ['category' => $facts->categoryCode],
            ];
        }

        $recoverable = '0.00';

        if (Decimal::isPositive($facts->vatAmount)) {
            if ($facts->merchantTaxId === null || $facts->merchantTaxId === '') {
                $violations[] = ['code' => 'missing_merchant_tax_id', 'severity' => 'warning', 'params' => []];
            }

            if ($facts->categoryVatRecoverable && $traceable && $facts->merchantTaxId !== null && $facts->merchantTaxId !== '') {
                $recoverable = $facts->vatAmount;
            } else {
                $violations[] = ['code' => 'vat_not_recoverable', 'severity' => 'warning', 'params' => []];
            }
        }

        return new LineAssessment($recoverable, $traceable, $taxable, $violations);
    }

    public function resolveMileageRate(MileageRateRequest $request): ResolvedMileageRate
    {
        $row = $this->rates->find(CalculationSource::ItAciTable->value, $request->vehicleClassCode, $request->onDate, $request->annualKmBracket);

        if ($row === null) {
            throw new ExpenseException('vehicle_rate_not_found', 422, ['vehicle' => $request->vehicleClassCode]);
        }

        return new ResolvedMileageRate(CalculationSource::ItAciTable, Decimal::normalize($row->rate_per_km, 4), $row->currency, $row->rate_period, $row->valid_from->toDateString());
    }

    public function calculatePerDiem(PerDiemFacts $facts): PerDiemResult
    {
        $abroad = $facts->destinationType === DestinationType::International || strtoupper($facts->countryCode) !== 'IT';

        $base = match ($facts->method) {
            AllowanceMethod::Analytic => $abroad ? self::AnalyticAbroadLimit : self::AnalyticDomesticLimit,
            default => $abroad ? self::AbroadLimit : self::DomesticLimit,
        };

        if ($facts->destinationType === DestinationType::Municipality) {
            $base = '0.00';
        }

        $effective = $facts->method === AllowanceMethod::Mixed
            ? PerDiemReductions::apply($base, $facts->mealsReimbursed, $facts->lodgingReimbursed)
            : $base;

        return PerDiemReductions::result($facts, $base, $effective, $facts->method === AllowanceMethod::Analytic ? '0.00' : $effective);
    }

    public function noticeCodes(): array
    {
        return ['it_art51_tuir', 'it_traceable_payment', 'it_aci_tables', 'it_per_diem_limits'];
    }
}

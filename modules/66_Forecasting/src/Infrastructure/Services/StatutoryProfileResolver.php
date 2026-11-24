<?php

declare(strict_types=1);

namespace Modules\Forecasting\Infrastructure\Services;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Modules\Forecasting\Domain\Enums\JurisdictionCode;
use Modules\Forecasting\Domain\ValueObjects\StatutoryProfile;
use Modules\Forecasting\Infrastructure\Persistence\ForecastingDataReader;

/**
 * Sizes the statutory events of a tenant from its history: the payroll runs of the last 12 months (salaries, withholding
 * and contributions) and the latest VAT settlements. Values the tenant states in settings.forecasting override the estimate.
 */
final readonly class StatutoryProfileResolver
{
    public function __construct(private ForecastingDataReader $reader) {}

    public function jurisdiction(Tenant $tenant): JurisdictionCode
    {
        return JurisdictionCode::fromCountry($tenant->country);
    }

    public function resolve(Tenant $tenant, CarbonImmutable $today): StatutoryProfile
    {
        $tenantId = (string) $tenant->id;
        $months = $this->reader->payrollTotals($tenantId, $today->subMonths(12)->startOfMonth());
        $monthCount = max(1, count($months));
        $averageGross = array_sum(array_column($months, 'gross')) / $monthCount;
        $averageContributions = array_sum(array_column($months, 'contributions')) / $monthCount;
        $vat = $this->reader->recentVatPayments($tenantId);
        $quarterlyVat = $vat === [] ? 0.0 : array_sum($vat) / count($vat);
        $isItaly = $this->jurisdiction($tenant) === JurisdictionCode::It;

        $estimate = [
            'monthly_payroll' => $averageGross,
            'monthly_withholding_and_contributions' => $isItaly ? $averageContributions : 0.0,
            'quarterly_vat' => $quarterlyVat,
            'vat_advance' => $isItaly ? round($quarterlyVat * 0.88, 2) : 0.0,
            'quarterly_social_security' => $isItaly ? 0.0 : $averageContributions * 3,
            'social_security_reconciliation' => $isItaly ? 0.0 : $averageContributions * 12 * 0.05,
        ];

        $settings = is_array($tenant->settings['forecasting'] ?? null) ? $tenant->settings['forecasting'] : [];

        return StatutoryProfile::fromArray($settings + $estimate);
    }
}

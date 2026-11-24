<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services\Receivables;

use Carbon\CarbonInterface;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Models\CustomerCreditLimit;
use Modules\Invoicing\Models\CustomerCreditOverride;
use Modules\Invoicing\Models\CustomerCreditProfile;

/**
 * KPIs of the receivables dashboard: total exposure, overdue amount, global DSO, prudential provision, blocked
 * customers, with the aging of the portfolio.
 */
final class ReceivablesDashboardService
{
    public function __construct(
        private readonly ReceivableAgingService $aging,
        private readonly DsoService $dso,
        private readonly BadDebtProvisionService $provision,
        private readonly CreditExposureService $exposure,
        private readonly CreditEnforcementService $enforcement,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(string $tenantId, CarbonInterface $today, ?string $currency = null): array
    {
        $aging = $this->aging->aging($tenantId, $today, null, $currency);
        $dso = $this->dso->calculate($tenantId, $today, null, DsoService::DefaultPeriodDays, $aging['currency']);
        $provision = $this->provision->calculate($tenantId, $today, null, $aging['currency']);

        $customerIds = collect([
            ...CustomerCreditLimit::query()->where('tenant_id', $tenantId)->effectiveOn($today)->pluck('customer_id')->all(),
            ...CustomerCreditProfile::query()->where('tenant_id', $tenantId)->where('is_credit_hold', true)->pluck('customer_id')->all(),
        ])->unique()->values();

        $blocked = [];
        $exposureTotal = '0';

        foreach ($customerIds as $customerId) {
            $decision = $this->enforcement->evaluate($tenantId, (string) $customerId, 'order', '0');

            if ($decision->blocks()) {
                $blocked[] = ['customer_id' => (string) $customerId, 'reason' => $decision->reason, 'exposure' => $decision->exposure, 'limit' => $decision->limit];
            }
        }

        foreach ($aging['customers'] as $row) {
            $figures = $this->exposure->calculate($tenantId, $row['customer_id'], $today, null, false);
            $exposureTotal = Decimal::add($exposureTotal, Decimal::isPositive($figures['total_exposure']) ? $figures['total_exposure'] : '0');
        }

        return [
            'as_of' => $today->toDateString(),
            'currency' => $aging['currency'],
            'kpis' => [
                'total_exposure' => Decimal::round2($exposureTotal),
                'total_open' => $aging['total_open'],
                'overdue_amount' => $aging['total_overdue'],
                'over_90' => $aging['over_90'],
                'dso' => $dso['dso'],
                'bad_debt_provision' => $provision['total_provision'],
                'net_realizable_value' => $provision['net_realizable_value'],
                'blocked_customers' => count($blocked),
                'active_overrides' => CustomerCreditOverride::query()->where('tenant_id', $tenantId)->active(now())->count(),
            ],
            'aging' => $aging,
            'blocked' => $blocked,
        ];
    }
}

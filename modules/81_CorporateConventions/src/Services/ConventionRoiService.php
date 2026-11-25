<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Services;

use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Modules\CorporateConventions\Domain\Enums\BeneficiaryType;
use Modules\CorporateConventions\Domain\Enums\ConventionStatus;
use Modules\CorporateConventions\Domain\Enums\EnrollmentStatus;
use Modules\CorporateConventions\Domain\Enums\ReimbursementStatus;
use Modules\CorporateConventions\Domain\Enums\SplitBillingStatus;
use Modules\CorporateConventions\Domain\Support\Dec;
use Modules\CorporateConventions\Models\ConventionBeneficiaryEnrollment;
use Modules\CorporateConventions\Models\ConventionSplitBilling;
use Modules\CorporateConventions\Models\CorporatePartnerConvention;
use Modules\CorporateConventions\Models\WelfareVoucherRedemption;

/**
 * Return on investment of the conventions for the commercial director, in one currency at a time (amounts of different currencies
 * are never added): enrolled employees and family members, revenue, discount granted, employer quota billed, margin.
 *
 * Revenue is the value of the purchases after the promotions already on the lines; net revenue deducts the convention discount; net
 * margin deducts the cost of the goods and the merchant fees withheld by welfare voucher providers.
 */
final class ConventionRoiService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @param  array{from?: string|null, to?: string|null, currency?: string|null, country_code?: string|null, convention_id?: string|null}  $filters
     * @return array{currency: string, kpis: array<string, mixed>, conventions: list<array<string, mixed>>}
     */
    public function analytics(array $filters): array
    {
        $currency = strtoupper((string) ($filters['currency'] ?? $this->tenantContext->getTenant()->default_currency ?? 'EUR'));
        $conventions = CorporatePartnerConvention::query()
            ->where('currency', $currency)
            ->when($filters['country_code'] ?? null, fn (Builder $query, string $value) => $query->where('country_code', $value))
            ->when($filters['convention_id'] ?? null, fn (Builder $query, string $value) => $query->whereKey($value))
            ->orderBy('name')->get();
        $ids = $conventions->pluck('id')->all();

        $enrolled = $this->enrolled($ids);
        $sales = $this->sales($ids, $filters);
        $fees = $this->welfareFees($ids, $filters);
        $pendingWelfare = $this->pendingWelfare($currency);

        $rows = [];
        $totals = ['employees' => 0, 'family' => 0, 'transactions' => 0, 'gross' => '0', 'discount' => '0', 'subsidy' => '0', 'cost' => '0', 'fees' => '0', 'pending_quota' => '0'];

        foreach ($conventions as $convention) {
            $enrollment = $enrolled[$convention->id] ?? ['employees' => 0, 'family' => 0];
            $sale = $sales[$convention->id] ?? ['transactions' => 0, 'gross' => '0', 'discount' => '0', 'subsidy' => '0', 'billed' => '0', 'pending' => '0', 'cost' => '0'];
            $fee = $fees[$convention->id] ?? '0';
            $net = Dec::sub($sale['gross'], $sale['discount']);

            $rows[] = [
                'convention_id' => $convention->id,
                'code' => $convention->code,
                'name' => $convention->name,
                'partner_company_name' => $convention->partner_company_name,
                'country_code' => $convention->country_code,
                'status' => $convention->status->value,
                'enrolled_employees' => $enrollment['employees'],
                'enrolled_family_members' => $enrollment['family'],
                'eligible_population' => $convention->eligible_population,
                'conversion_rate' => $convention->eligible_population ? Dec::round(Dec::mul(Dec::div((string) $enrollment['employees'], (string) $convention->eligible_population), '100')) : null,
                'transactions' => $sale['transactions'],
                'gross_revenue' => Dec::round($sale['gross']),
                'discount_granted' => Dec::round($sale['discount']),
                'net_revenue' => Dec::round($net),
                'average_discount_percentage' => $this->percentage($sale['discount'], $sale['gross']),
                'employer_subsidy_total' => Dec::round($sale['subsidy']),
                'employer_subsidy_billed' => Dec::round($sale['billed']),
                'employer_subsidy_pending' => Dec::round($sale['pending']),
                'cost_amount' => Dec::round($sale['cost']),
                'welfare_fees' => Dec::round($fee),
                'net_margin' => Dec::round(Dec::sub(Dec::sub($net, $sale['cost']), $fee)),
            ];

            $totals['employees'] += $enrollment['employees'];
            $totals['family'] += $enrollment['family'];
            $totals['transactions'] += $sale['transactions'];
            $totals['gross'] = Dec::add($totals['gross'], $sale['gross']);
            $totals['discount'] = Dec::add($totals['discount'], $sale['discount']);
            $totals['subsidy'] = Dec::add($totals['subsidy'], $sale['billed']);
            $totals['cost'] = Dec::add($totals['cost'], $sale['cost']);
            $totals['fees'] = Dec::add($totals['fees'], $fee);
            $totals['pending_quota'] = Dec::add($totals['pending_quota'], $sale['pending']);
        }

        $net = Dec::sub($totals['gross'], $totals['discount']);

        return [
            'currency' => $currency,
            'kpis' => [
                'active_conventions' => $conventions->where('status', ConventionStatus::Active)->count(),
                'enrolled_employees' => $totals['employees'],
                'enrolled_family_members' => $totals['family'],
                'transactions' => $totals['transactions'],
                'gross_revenue' => Dec::round($totals['gross']),
                'discount_granted' => Dec::round($totals['discount']),
                'net_revenue' => Dec::round($net),
                'average_discount_percentage' => $this->percentage($totals['discount'], $totals['gross']),
                'employer_subsidy_billed' => Dec::round($totals['subsidy']),
                'net_margin' => Dec::round(Dec::sub(Dec::sub($net, $totals['cost']), $totals['fees'])),
                'pending_split_billing_amount' => Dec::round($totals['pending_quota']),
                'pending_welfare_vouchers' => $pendingWelfare['count'],
                'pending_welfare_amount' => $pendingWelfare['net'],
            ],
            'conventions' => $rows,
        ];
    }

    /**
     * The analytics as CSV rows (header first), for the export of the dashboard.
     *
     * @param  array<string, mixed>  $filters
     * @return list<list<string>>
     */
    public function exportRows(array $filters): array
    {
        $columns = ['code', 'name', 'partner_company_name', 'country_code', 'status', 'enrolled_employees', 'enrolled_family_members', 'eligible_population', 'conversion_rate', 'transactions', 'gross_revenue', 'discount_granted', 'net_revenue', 'average_discount_percentage', 'employer_subsidy_billed', 'employer_subsidy_pending', 'cost_amount', 'welfare_fees', 'net_margin'];
        $rows = [$columns];

        foreach ($this->analytics($filters)['conventions'] as $row) {
            $rows[] = array_map(static fn (string $column): string => (string) ($row[$column] ?? ''), $columns);
        }

        return $rows;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array{employees: int, family: int}>
     */
    private function enrolled(array $ids): array
    {
        $result = [];
        $rows = ConventionBeneficiaryEnrollment::query()
            ->selectRaw('convention_id, beneficiary_type, COUNT(*) as total')
            ->whereIn('convention_id', $ids)->where('status', EnrollmentStatus::Active->value)
            ->groupBy('convention_id', 'beneficiary_type')->get();

        foreach ($rows as $row) {
            $key = (string) $row->convention_id;
            $result[$key] ??= ['employees' => 0, 'family' => 0];
            $field = (string) $row->getRawOriginal('beneficiary_type') === BeneficiaryType::FamilyMember->value ? 'family' : 'employees';
            $result[$key][$field] += (int) $row->getAttribute('total');
        }

        return $result;
    }

    /**
     * @param  list<string>  $ids
     * @param  array<string, mixed>  $filters
     * @return array<string, array{transactions: int, gross: string, discount: string, subsidy: string, billed: string, pending: string, cost: string}>
     */
    private function sales(array $ids, array $filters): array
    {
        $rows = ConventionSplitBilling::query()
            ->selectRaw('convention_id, billing_status, COUNT(*) as transactions, SUM(total_gross_amount) as gross, SUM(convention_discount_amount) as discount, SUM(employer_subsidy_amount) as subsidy, SUM(cost_amount) as cost')
            ->whereIn('convention_id', $ids)
            ->when($filters['from'] ?? null, fn (Builder $query, string $value) => $query->whereDate('transaction_date', '>=', $value))
            ->when($filters['to'] ?? null, fn (Builder $query, string $value) => $query->whereDate('transaction_date', '<=', $value))
            ->groupBy('convention_id', 'billing_status')->get();

        $result = [];

        foreach ($rows as $row) {
            $key = (string) $row->convention_id;
            $result[$key] ??= ['transactions' => 0, 'gross' => '0', 'discount' => '0', 'subsidy' => '0', 'billed' => '0', 'pending' => '0', 'cost' => '0'];
            $status = (string) $row->getRawOriginal('billing_status');
            $subsidy = (string) $row->getAttribute('subsidy');
            $result[$key]['transactions'] += (int) $row->getAttribute('transactions');
            $result[$key]['gross'] = Dec::add($result[$key]['gross'], (string) $row->getAttribute('gross'));
            $result[$key]['discount'] = Dec::add($result[$key]['discount'], (string) $row->getAttribute('discount'));
            $result[$key]['subsidy'] = Dec::add($result[$key]['subsidy'], $subsidy);
            $result[$key]['cost'] = Dec::add($result[$key]['cost'], (string) $row->getAttribute('cost'));

            if (in_array($status, [SplitBillingStatus::Invoiced->value, SplitBillingStatus::Paid->value], true)) {
                $result[$key]['billed'] = Dec::add($result[$key]['billed'], $subsidy);
            } elseif ($status === SplitBillingStatus::PendingConsolidation->value) {
                $result[$key]['pending'] = Dec::add($result[$key]['pending'], $subsidy);
            }
        }

        return $result;
    }

    /**
     * Merchant fees of the welfare vouchers redeemed by the beneficiaries of each convention.
     *
     * @param  list<string>  $ids
     * @param  array<string, mixed>  $filters
     * @return array<string, string>
     */
    private function welfareFees(array $ids, array $filters): array
    {
        $rows = WelfareVoucherRedemption::query()
            ->join('sales_convention_enrollments as enrollment', 'enrollment.id', '=', 'pos_welfare_voucher_redemptions.enrollment_id')
            ->selectRaw('enrollment.convention_id as convention_id, SUM(pos_welfare_voucher_redemptions.merchant_fee_amount) as fees')
            ->whereIn('enrollment.convention_id', $ids)
            ->when($filters['from'] ?? null, fn (Builder $query, string $value) => $query->whereDate('pos_welfare_voucher_redemptions.created_at', '>=', $value))
            ->when($filters['to'] ?? null, fn (Builder $query, string $value) => $query->whereDate('pos_welfare_voucher_redemptions.created_at', '<=', $value))
            ->groupBy('enrollment.convention_id')->get();

        $result = [];

        foreach ($rows as $row) {
            $result[(string) $row->getAttribute('convention_id')] = (string) $row->getAttribute('fees');
        }

        return $result;
    }

    /**
     * @return array{count: int, net: string}
     */
    private function pendingWelfare(string $currency): array
    {
        $query = WelfareVoucherRedemption::query()->where('currency', $currency)
            ->whereIn('reimbursement_status', [ReimbursementStatus::PendingSettlement->value, ReimbursementStatus::SubmittedToProvider->value]);

        return ['count' => (clone $query)->count(), 'net' => Dec::round((string) ((clone $query)->sum('net_expected_reimbursement') ?: '0'))];
    }

    private function percentage(string $part, string $whole): string
    {
        return Dec::cmp($whole, '0') === 0 ? '0.00' : Dec::round(Dec::mul(Dec::div($part, $whole), '100'));
    }
}

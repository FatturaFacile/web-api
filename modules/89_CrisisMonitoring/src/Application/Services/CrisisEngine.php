<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Application\Services;

use App\Models\PassiveInvoice;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Modules\CrisisMonitoring\Domain\Ccii\CncPracticalTest;
use Modules\CrisisMonitoring\Domain\Ccii\EarlyWarningEvaluator;
use Modules\CrisisMonitoring\Domain\Ccii\PublicCreditorMonitor;
use Modules\CrisisMonitoring\Domain\Dscr\DscrBands;
use Modules\CrisisMonitoring\Domain\Dscr\DscrCalculator;
use Modules\CrisisMonitoring\Domain\Dscr\MonthlyBucket;
use Modules\CrisisMonitoring\Domain\Enums\TrafficLight;
use Modules\CrisisMonitoring\Domain\Stress\StressShock;
use Modules\CrisisMonitoring\Domain\Swiss\RangruecktrittCalculator;
use Modules\CrisisMonitoring\Domain\Swiss\SwissCo725Evaluator;
use Modules\CrisisMonitoring\Models\CncPracticalTestTier;
use Modules\CrisisMonitoring\Models\CndcecAtecoThreshold;
use Modules\CrisisMonitoring\Models\CrisisProfile;
use Modules\CrisisMonitoring\Models\PublicCreditorThreshold;
use Modules\CrisisMonitoring\Models\SubordinatedLoanRangruecktritt;
use Modules\CrisisMonitoring\Support\AtecoSection;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\LiquidityPlanning\Services\ForecastService;
use Modules\LiquidityPlanning\Support\Flow;
use Modules\LiquidityPlanning\Support\Levers;
use Modules\LiquidityPlanning\Support\ScenarioApplier;

/**
 * The crisis computation of Mod 357. It reads the cash flows of Mod 017 (receivable and payable schedules, orders, contracts, loan and
 * lease schedules, payroll, tax and manual items) over twelve months, the ledger and the crisis profile once (`load`), and evaluates
 * them (`evaluate`) as many times as needed: the baseline, the stress shocks and the sensitivity tables, without ever writing a record.
 *
 * Classification of the flows: loan principal and interest and lease payments are the financial debt service; tax and VAT payments the
 * tax and social outflows; the supplier invoices already overdue at the snapshot the overdue supplier debt; capex the investments;
 * every other outflow is operating. The additional financing of a scenario is not an operating receipt and is left out.
 */
final readonly class CrisisEngine
{
    public const int HorizonDays = 370;

    public const int Months = 12;

    public function __construct(
        private ForecastService $forecast,
        private CrisisLedgerReader $ledger,
        private ScenarioApplier $applier,
        private EarlyWarningEvaluator $earlyWarning,
        private PublicCreditorMonitor $creditors,
        private CncPracticalTest $cnc,
        private SwissCo725Evaluator $swiss,
        private RangruecktrittCalculator $rangruecktritt,
    ) {}

    /**
     * Reads everything the evaluation needs from the records of the current company.
     *
     * @return array<string, mixed>
     */
    public function load(Tenant $tenant, ?CarbonImmutable $asOf = null): array
    {
        $data = $this->forecast->collect($tenant, self::HorizonDays, $asOf);
        $start = $data['start'];
        $tenantId = (string) $tenant->id;
        $profile = CrisisProfile::current((string) $tenant->country);
        $section = $profile->ateco_section ?? AtecoSection::fromCode($profile->ateco_code);

        $thresholdRows = $section === null ? collect() : CndcecAtecoThreshold::query()->where('ateco_section', $section)->where('is_active', true)->get();
        $threshold = $thresholdRows->sortBy(static fn (CndcecAtecoThreshold $row): int => $row->tenant_id === null ? 1 : 0)->first();

        $rules = PublicCreditorThreshold::query()->where('is_active', true)->get()
            ->sortBy(static fn (PublicCreditorThreshold $row): int => $row->tenant_id === null ? 1 : 0)
            ->map(static fn (PublicCreditorThreshold $row): array => $row->attributesToArray())
            ->values()->all();

        $tiers = CncPracticalTestTier::query()->where('is_active', true)->orderBy('sort_order')->get()
            ->map(static fn (CncPracticalTestTier $tier): array => ['code' => (string) $tier->code, 'label_key' => (string) $tier->label_key, 'ratio_up_to' => $tier->ratio_up_to === null ? null : (string) $tier->ratio_up_to, 'status' => (string) $tier->status])
            ->all();

        return $data + [
            'tenant_id' => $tenantId,
            'profile' => $profile,
            'ateco_section' => $section,
            'ateco_threshold' => $threshold === null ? null : [
                'financial_charges_revenue_max' => (string) $threshold->financial_charges_revenue_max,
                'equity_total_debt_min' => (string) $threshold->equity_total_debt_min,
                'liquidity_ratio_min' => (string) $threshold->liquidity_ratio_min,
                'cash_flow_assets_min' => (string) $threshold->cash_flow_assets_min,
                'tax_social_debt_assets_max' => (string) $threshold->tax_social_debt_assets_max,
                'sector_label_key' => (string) $threshold->sector_label_key,
            ],
            'rules' => $rules,
            'tiers' => $tiers,
            'book' => $this->ledger->read($tenantId, $start),
            'loans' => $this->loans(),
            'overdue_suppliers_90d' => $this->overdueSuppliers($tenantId, $start, 90),
        ];
    }

    /**
     * The full picture of the company under a shock (the neutral shock is the baseline).
     *
     * @param  array<string, mixed>  $inputs  see load()
     * @return array<string, mixed>
     */
    public function evaluate(array $inputs, ?StressShock $shock = null): array
    {
        $shock ??= new StressShock;
        /** @var CarbonImmutable $start */
        $start = $inputs['start'];
        /** @var CrisisProfile $profile */
        $profile = $inputs['profile'];
        $settings = $inputs['settings'];
        /** @var list<Flow> $baseFlows */
        $baseFlows = $inputs['flows'];
        $book = $inputs['book'];
        $position = $inputs['position'];

        $flows = $this->shocked($baseFlows, $shock, $start, $profile);
        $buckets = $this->buckets($flows, $start);
        $credit = $profile->money('available_credit_lines') ?? '0';
        $calculator = new DscrCalculator(new DscrBands((string) $settings->dscr_critical_threshold, (string) $settings->dscr_warning_threshold));
        $projection = $calculator->project($buckets, $inputs['opening']['total'], $credit);
        $impact = $this->equityImpact($baseFlows, $shock, $start, $profile);

        $netEquityBase = $profile->money('net_equity_override') ?? ($settings->net_equity_override !== null ? (string) $settings->net_equity_override : $position['net_equity']);
        $netEquity = Decimal::sub($netEquityBase, $impact);
        $revenueBase = $profile->money('revenue_override') ?? (Decimal::isPositive($book['revenue']) ? $book['revenue'] : ($profile->money('prior_year_revenue') ?? '0'));
        $revenue = Decimal::mul($revenueBase, Decimal::sub('1', Decimal::div($shock->revenueDropPercent, '100')));
        $interestBase = $profile->money('financial_expenses_override') ?? $this->sum($baseFlows, $start, static fn (Flow $flow): bool => $flow->category === 'debt_interest' && ! $flow->isInflow());
        $extraInterest = $this->extraInterestAnnual($profile, $shock);
        $operatingCashFlow = '0';
        $freeCashFlow = '0';

        foreach ($buckets as $bucket) {
            $operatingCashFlow = Decimal::add($operatingCashFlow, Decimal::sub(Decimal::sub($bucket->inflows, $bucket->operatingOutflows), $bucket->taxSocial));
            $freeCashFlow = Decimal::add($freeCashFlow, Decimal::sub($calculator->freeCashFlow($bucket), $bucket->taxSocial));
        }

        $taxSocialDebt = $profile->money('tax_social_debt_override') ?? $this->sum($baseFlows, $start, static fn (Flow $flow): bool => $flow->category === 'tax' && $flow->date->equalTo($start) && ! $flow->isInflow(), false);
        $figures = [
            'net_equity' => Decimal::round2($netEquity),
            'legal_minimum_capital' => $profile->money('legal_minimum_capital') ?? ($settings->legal_minimum_capital !== null ? (string) $settings->legal_minimum_capital : null),
            'financial_expenses' => Decimal::add($interestBase, $extraInterest),
            'revenue' => $revenue,
            'total_debt' => $profile->money('total_debt_override') ?? $book['total_liabilities'],
            'total_assets' => Decimal::sub($profile->money('total_assets_override') ?? $book['total_assets'], $impact),
            'current_assets' => Decimal::sub($profile->money('current_assets_override') ?? $book['current_assets'], $impact),
            'current_liabilities' => $profile->money('current_liabilities_override') ?? $book['current_liabilities'],
            'cash_flow' => $profile->money('cash_flow_override') !== null ? Decimal::sub((string) $profile->money('cash_flow_override'), $impact) : $operatingCashFlow,
            'tax_social_debt' => $taxSocialDebt,
        ];

        $early = $this->earlyWarning->evaluate($figures, $projection['dscr']['treasury']['m6'], $inputs['ateco_threshold']);
        $supplierOverdue = $profile->money('supplier_overdue_90d_amount') ?? $inputs['overdue_suppliers_90d'];
        $creditors = $this->creditors->evaluate($inputs['rules'], [
            'legal_form' => (string) $profile->legal_form,
            'employees_count' => (int) $profile->employees_count,
            'measured' => [
                'payroll' => $profile->money('payroll_overdue_30d_amount') ?? '0',
                'supplier' => $supplierOverdue,
                'inps' => $profile->money('inps_overdue_90d_amount') ?? '0',
                'inail' => $profile->money('inail_overdue_90d_amount') ?? '0',
                'ade_vat' => $profile->money('vat_lipe_unpaid_amount') ?? '0',
                'ader' => $profile->money('ader_entrusted_amount') ?? '0',
            ],
            'bases' => [
                'monthly_payroll' => $this->monthlyPayroll($baseFlows, $start),
                'non_overdue_suppliers' => $this->sum($baseFlows, $start, static fn (Flow $flow): bool => $flow->category === 'payable' && $flow->date->greaterThan($start) && ! $flow->isInflow(), false),
                'prior_year_contributions' => $profile->money('prior_year_inps_contributions') ?? '0',
                'prior_year_turnover' => $profile->money('prior_year_revenue') ?? '0',
            ],
        ]);

        $debtToRestructure = Decimal::add(Decimal::add($position['financial_debt'], $taxSocialDebt), $supplierOverdue);
        $checklist = [
            'ateco_configured' => $inputs['ateco_section'] !== null,
            'financial_debt_known' => Decimal::isPositive($position['financial_debt']) || $profile->money('total_debt_override') !== null ? true : null,
            'tax_social_assessed' => $profile->money('tax_social_debt_override') !== null || Decimal::isPositive($taxSocialDebt),
            'public_creditor_data' => $profile->money('inps_overdue_90d_amount') !== null || $profile->money('vat_lipe_unpaid_amount') !== null || $profile->money('ader_entrusted_amount') !== null,
            'cash_flow_positive' => Decimal::isPositive($freeCashFlow),
            'projection_covers_year' => true,
        ];
        $cnc = $this->cnc->evaluate($debtToRestructure, $freeCashFlow, $inputs['tiers'], $checklist);

        $ccii = [
            'overall_status' => $early['overall_status'],
            'primary_triggers' => $early['primary_triggers'],
            'ateco' => ['code' => $profile->ateco_code, 'section' => $inputs['ateco_section'], 'sector_label_key' => $inputs['ateco_threshold']['sector_label_key'] ?? null],
            'indicators' => $early['indicators'],
            'public_creditors' => $creditors,
            'cnc' => $cnc,
        ];

        $swiss = $this->swissSection($inputs, $profile, $projection, $netEquity, $impact);
        $jurisdiction = (string) $profile->jurisdiction;
        $overall = TrafficLight::worst([$jurisdiction === 'CH' ? $swiss['overall_status'] : $ccii['overall_status']]);

        return [
            'as_of' => $start->toDateString(),
            'currency' => $inputs['currency'],
            'jurisdiction' => $jurisdiction,
            'overall_status' => $overall->value,
            'dscr' => $projection['dscr'],
            'timeline' => $projection['months'],
            'ccii' => $ccii,
            'swiss' => $swiss,
        ];
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @param  array{months: list<array<string, mixed>>, dscr: array<string, mixed>}  $projection
     * @return array<string, mixed>
     */
    private function swissSection(array $inputs, CrisisProfile $profile, array $projection, string $netEquity, string $impact): array
    {
        /** @var CarbonImmutable $start */
        $start = $inputs['start'];
        $book = $inputs['book'];
        $settings = $inputs['settings'];
        $credit = $profile->money('available_credit_lines') ?? '0';
        $capital = $profile->money('share_capital') ?? ($settings->share_capital !== null ? (string) $settings->share_capital : null);

        $liquidity = $this->swiss->liquidity($projection['months'], $inputs['opening']['total'], $credit);
        $capitalLoss = $this->swiss->capitalLoss($netEquity, $capital, $profile->money('statutory_reserves'));

        $assetsGoingConcern = Decimal::sub($profile->money('assets_going_concern') ?? $profile->money('total_assets_override') ?? $book['total_assets'], $impact);
        $assetsLiquidation = $profile->money('assets_liquidation') !== null ? Decimal::sub((string) $profile->money('assets_liquidation'), $impact) : $assetsGoingConcern;
        $liabilities = $profile->money('total_liabilities_override') ?? $book['total_liabilities'];

        $covered = $this->rangruecktritt->covered($inputs['loans'], $start, self::Months);
        $over = $this->swiss->overIndebtedness($assetsGoingConcern, $assetsLiquidation, $liabilities, (bool) $profile->going_concern_probable, $covered['claims']);
        $subordination = $this->subordination($over, $covered['claims'], $inputs['loans'], null);

        return [
            'overall_status' => TrafficLight::worst([$liquidity['status'], $capitalLoss['status'], $over['status']])->value,
            'art725' => $liquidity,
            'art725a' => $capitalLoss,
            'art725b' => $over,
            'subordination' => $subordination,
        ];
    }

    /**
     * The subordination figures of an over-indebtedness result: what is covered and what an agreement must still cover.
     *
     * @param  array<string, mixed>  $over  see SwissCo725Evaluator::overIndebtedness()
     * @param  list<array<string, mixed>>  $loans
     * @return array<string, mixed>
     */
    public function subordination(array $over, string $covered, array $loans, ?string $ratePercent, int $horizonMonths = self::Months): array
    {
        $required = $this->rangruecktritt->required((string) $over['shortfall'], $covered, $horizonMonths, $ratePercent ?? $this->weightedRate($loans));

        return [
            'basis' => $over['basis'],
            'horizon_months' => $horizonMonths,
            'shortfall' => $over['shortfall'],
            'active_claims_covered' => Decimal::round2($covered),
            'remaining_shortfall' => $required['remaining_shortfall'],
            'assumed_rate' => $required['assumed_rate'],
            'required_claim_amount' => $required['required_claim_amount'],
            'required_principal_amount' => $required['required_principal_amount'],
            'court_notification_required' => Decimal::isPositive($required['remaining_shortfall']),
        ];
    }

    /**
     * The Rangrücktritt answer for a horizon, a rate and a valuation basis chosen by the user.
     *
     * @param  array<string, mixed>  $inputs  see load()
     * @return array<string, mixed>
     */
    public function requiredRangruecktritt(array $inputs, int $horizonMonths, ?string $ratePercent, ?string $basis): array
    {
        /** @var CarbonImmutable $start */
        $start = $inputs['start'];
        /** @var CrisisProfile $profile */
        $profile = $inputs['profile'];
        $book = $inputs['book'];
        $assetsGoingConcern = $profile->money('assets_going_concern') ?? $profile->money('total_assets_override') ?? $book['total_assets'];
        $assetsLiquidation = $profile->money('assets_liquidation') ?? $assetsGoingConcern;
        $liabilities = $profile->money('total_liabilities_override') ?? $book['total_liabilities'];
        $covered = $this->rangruecktritt->covered($inputs['loans'], $start, $horizonMonths);
        $probable = $basis === null ? (bool) $profile->going_concern_probable : $basis === 'going_concern';
        $over = $this->swiss->overIndebtedness($assetsGoingConcern, $assetsLiquidation, $liabilities, $probable, $covered['claims']);

        if ($basis !== null) {
            // A basis chosen by the user is the one to cure, whatever the cheaper way out would be.
            $net = $basis === 'going_concern' ? Decimal::sub($liabilities, $assetsGoingConcern) : Decimal::sub($liabilities, $assetsLiquidation);
            $over['basis'] = $basis;
            $over['shortfall'] = Decimal::isPositive($net) ? Decimal::round2($net) : '0.00';
        }

        return $this->subordination($over, $covered['claims'], $inputs['loans'], $ratePercent, $horizonMonths);
    }

    /**
     * The claims of the registry as arrays for the calculator, with the per-loan accrued interest and claim at the horizon.
     *
     * @param  list<array<string, mixed>>  $loans
     * @return list<array<string, string>>
     */
    public function loanClaims(array $loans, CarbonImmutable $asOf, int $horizonMonths = self::Months): array
    {
        $claims = [];

        foreach ($loans as $loan) {
            $claims[] = $this->rangruecktritt->claim($loan, $asOf, $horizonMonths);
        }

        return $claims;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loans(): array
    {
        return SubordinatedLoanRangruecktritt::query()->orderBy('effective_from')->get()
            ->map(static fn (SubordinatedLoanRangruecktritt $loan): array => [
                'id' => (string) $loan->id,
                'principal' => (string) $loan->principal,
                'interest_rate' => (string) $loan->interest_rate,
                'interest_treatment' => (string) $loan->interest_treatment,
                'scope' => (string) $loan->scope,
                'status' => (string) $loan->status,
                'effective_from' => CarbonImmutable::parse($loan->effective_from),
                'deferral_end_date' => $loan->deferral_end_date === null ? null : CarbonImmutable::parse($loan->deferral_end_date),
            ])->all();
    }

    /**
     * Supplier invoices open and overdue by more than the given days at the snapshot.
     */
    private function overdueSuppliers(string $tenantId, CarbonImmutable $start, int $days): string
    {
        $total = '0';
        $cutoff = $start->subDays($days)->toDateString();

        $invoices = PassiveInvoice::query()
            ->where('tenant_id', $tenantId)
            ->where('document_type', '!=', 'TD04')
            ->where(fn ($query) => $query->whereNull('payment_status')->orWhere('payment_status', '!=', 'paid'))
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $cutoff)
            ->get();

        foreach ($invoices as $invoice) {
            $open = Decimal::sub($invoice->amountDue(), (string) ($invoice->total_paid ?? '0'));

            if (Decimal::isPositive($open)) {
                $total = Decimal::add($total, $open);
            }
        }

        return Decimal::round2($total);
    }

    /**
     * @param  list<Flow>  $flows
     * @return list<Flow>
     */
    private function shocked(array $flows, StressShock $shock, CarbonImmutable $start, CrisisProfile $profile): array
    {
        if ($shock->isNeutral()) {
            return $flows;
        }

        $levers = Levers::fromArray([
            'inflow_change_percent' => '-'.$shock->revenueDropPercent,
            'collection_delay_days' => $shock->dsoDelayDays,
            'collection_delay_share_percent' => 100,
        ]);
        $flows = $this->applier->apply($flows, $levers, $start);

        if ($shock->interestRateBps > 0) {
            $monthly = Decimal::round2(Decimal::div($this->extraInterestAnnual($profile, $shock), (string) self::Months));

            if (Decimal::isPositive($monthly)) {
                for ($index = 0; $index < self::Months; $index++) {
                    $flows[] = new Flow($start->addMonthsNoOverflow($index), Flow::Outflow, Flow::Certain, 'debt_interest', 'Rate shock', $monthly, 'crisisApi.stress.rate_shock');
                }
            }
        }

        if (Decimal::isPositive($shock->oneOffOutflowAmount)) {
            $flows[] = new Flow($start->addDays(30), Flow::Outflow, Flow::Certain, 'other', 'Extraordinary outflow', $shock->oneOffOutflowAmount, 'crisisApi.stress.one_off');
        }

        return $flows;
    }

    /**
     * Annual interest added by a rate hike on the variable debt.
     */
    private function extraInterestAnnual(CrisisProfile $profile, StressShock $shock): string
    {
        $variable = $profile->money('variable_debt_amount');

        if ($variable === null || $shock->interestRateBps === 0) {
            return '0';
        }

        return Decimal::round2(Decimal::mul($variable, Decimal::div((string) $shock->interestRateBps, '10000')));
    }

    /**
     * What the shock takes out of the net equity over the year: the lost revenue, the extra interest and the extraordinary outflow. A
     * collection delay moves cash in time but changes no result, so it does not count.
     *
     * @param  list<Flow>  $baseFlows
     */
    private function equityImpact(array $baseFlows, StressShock $shock, CarbonImmutable $start, CrisisProfile $profile): string
    {
        if ($shock->isNeutral()) {
            return '0';
        }

        $customerInflows = $this->sum($baseFlows, $start, static fn (Flow $flow): bool => $flow->isCustomerInflow());
        $lost = Decimal::mul($customerInflows, Decimal::div($shock->revenueDropPercent, '100'));

        return Decimal::round2(Decimal::add(Decimal::add($lost, $this->extraInterestAnnual($profile, $shock)), $shock->oneOffOutflowAmount));
    }

    /**
     * @param  list<Flow>  $flows
     * @param  callable(Flow): bool  $filter
     */
    private function sum(array $flows, CarbonImmutable $start, callable $filter, bool $withinYear = true): string
    {
        $end = $start->addMonthsNoOverflow(self::Months);
        $total = '0';

        foreach ($flows as $flow) {
            if ($flow->date->lessThan($start) || ($withinYear && $flow->date->greaterThanOrEqualTo($end))) {
                continue;
            }

            if ($filter($flow)) {
                $total = Decimal::add($total, $flow->amount);
            }
        }

        return Decimal::round2($total);
    }

    /**
     * @param  list<Flow>  $flows
     */
    private function monthlyPayroll(array $flows, CarbonImmutable $start): string
    {
        $total = '0';
        $count = 0;
        $end = $start->addMonthsNoOverflow(self::Months);

        foreach ($flows as $flow) {
            if ($flow->category === 'payroll' && ! $flow->isInflow() && $flow->date->greaterThanOrEqualTo($start) && $flow->date->lessThan($end)) {
                $total = Decimal::add($total, $flow->amount);
                $count++;
            }
        }

        return $count === 0 ? '0' : Decimal::round2(Decimal::div($total, (string) $count));
    }

    /**
     * @param  list<array<string, mixed>>  $loans
     */
    private function weightedRate(array $loans): string
    {
        $weighted = '0';
        $principal = '0';

        foreach ($loans as $loan) {
            if ($loan['status'] === 'active') {
                $weighted = Decimal::add($weighted, Decimal::mul((string) $loan['principal'], (string) $loan['interest_rate']));
                $principal = Decimal::add($principal, (string) $loan['principal']);
            }
        }

        return Decimal::isPositive($principal) ? Decimal::round2(Decimal::div($weighted, $principal)) : '0.00';
    }

    /**
     * Splits the flows of the twelve months into the buckets of the DSCR.
     *
     * @param  list<Flow>  $flows
     * @return list<MonthlyBucket>
     */
    private function buckets(array $flows, CarbonImmutable $start): array
    {
        $starts = [];

        for ($index = 0; $index <= self::Months; $index++) {
            $starts[$index] = $start->addMonthsNoOverflow($index);
        }

        $acc = array_fill(0, self::Months, ['in' => '0', 'op' => '0', 'debt' => '0', 'tax' => '0', 'overdue' => '0', 'capex' => '0']);

        foreach ($flows as $flow) {
            if ($flow->date->lessThan($start) || $flow->date->greaterThanOrEqualTo($starts[self::Months])) {
                continue;
            }

            $index = 0;

            for ($candidate = self::Months - 1; $candidate >= 0; $candidate--) {
                if ($flow->date->greaterThanOrEqualTo($starts[$candidate])) {
                    $index = $candidate;

                    break;
                }
            }

            if ($flow->isInflow()) {
                if ($flow->labelKey !== 'lpApi.category.additional_financing') {
                    $acc[$index]['in'] = Decimal::add($acc[$index]['in'], $flow->amount);
                }

                continue;
            }

            $slot = match (true) {
                in_array($flow->category, ['debt_principal', 'debt_interest', 'lease'], true) => 'debt',
                $flow->category === 'tax' => 'tax',
                $flow->category === 'capex' => 'capex',
                $flow->category === 'payable' && $flow->date->equalTo($start) => 'overdue',
                default => 'op',
            };
            $acc[$index][$slot] = Decimal::add($acc[$index][$slot], $flow->amount);
        }

        $buckets = [];

        foreach ($acc as $index => $values) {
            $buckets[] = new MonthlyBucket($starts[$index], $values['in'], $values['op'], $values['debt'], $values['tax'], $values['overdue'], $values['capex']);
        }

        return $buckets;
    }
}

<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use App\Contracts\PdfGenerator;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Associations\Enums\MembershipStatus;
use Modules\Associations\Exceptions\AssociationException;
use Modules\Associations\Models\Campaign;
use Modules\Associations\Models\Donation;
use Modules\Associations\Models\GeneralInterestActivity;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\Membership;
use Modules\Associations\Models\MissionReport;
use Modules\Associations\Models\StatementFiling;
use Modules\Associations\Models\Volunteer;
use Modules\Associations\Support\CsvFile;
use Modules\Associations\Support\Money;
use Modules\Associations\Support\ThirdSectorScheme;

/**
 * The preventive checks of the statements, the outline of the mission report and the preparation of the filing. The
 * filing itself is made by the authorized person on the portal of the national register: what is prepared here is a
 * frozen snapshot and an export to support it, not an official file format. Thresholds come from the legal parameters;
 * when one is missing the related check says so instead of applying a figure.
 */
final class ThirdSectorFilingService
{
    public const array Forms = ['ordinary', 'cash'];

    public function __construct(
        private readonly ThirdSectorStatementService $statements,
        private readonly LegalParameterService $parameters,
        private readonly FundraisingService $fundraising,
        private readonly TenantContext $tenantContext,
        private readonly PdfGenerator $pdf,
    ) {}

    /**
     * @return list<array{code: string, severity: string, message: string, details: array<string, mixed>}>
     */
    public function checks(int $year, string $form): array
    {
        $checks = [];
        $management = $this->statements->managementReport($year);

        if ($management['unmapped'] !== []) {
            $checks[] = $this->check('unmapped_management_accounts', 'error', ['accounts' => $management['unmapped']]);
        }

        if ($form === 'ordinary') {
            $balance = $this->statements->balanceSheet($year);

            if ($balance['unmapped'] !== []) {
                $checks[] = $this->check('unmapped_balance_accounts', 'error', ['accounts' => $balance['unmapped']]);
            }

            if (Money::compare($balance['totals']['difference'], '0') !== 0) {
                $checks[] = $this->check('balance_sheet_not_balanced', 'error', ['difference' => $balance['totals']['difference']]);
            }

            if (Money::compare($balance['totals']['unclosed_earlier_results'], '0') !== 0) {
                $checks[] = $this->check('earlier_results_not_closed', 'warning', ['amount' => $balance['totals']['unclosed_earlier_results']]);
            }

            $missing = array_values(array_diff(array_keys(ThirdSectorScheme::MissionReportPoints), array_map('intval', array_keys(array_filter($this->missionAnswers($year))))));

            if ($missing !== []) {
                $checks[] = $this->check('mission_report_incomplete', 'warning', ['points_without_text' => $missing]);
            }
        } else {
            $cash = $this->statements->cashReport($year);

            if ($cash['unmapped'] !== []) {
                $checks[] = $this->check('unmapped_cash_accounts', 'error', ['accounts' => $cash['unmapped']]);
            }

            if (Money::compare($cash['totals']['reconciliation_difference'], '0') !== 0) {
                $checks[] = $this->check('cash_not_reconciled', 'error', ['difference' => $cash['totals']['reconciliation_difference']]);
            }
        }

        $checks[] = $this->formCheck($year, $form);
        $checks[] = $this->secondaryActivitiesCheck($year);
        $checks[] = $this->commercialPrevalenceCheck($year);

        return array_values(array_filter($checks));
    }

    /**
     * Whether the revenues of the year allow the cash form, against the threshold parameter of the year.
     *
     * @return array{code: string, severity: string, message: string, details: array<string, mixed>}
     */
    public function formCheck(int $year, string $form): array
    {
        $threshold = $this->parameters->value(LegalParameterService::CashStatementThreshold, $year);
        $revenues = $this->statements->revenueBreakdown($year)['total'];

        if ($threshold === null) {
            return $this->check('cash_threshold_not_configured', 'info', ['revenues' => $revenues, 'parameter' => LegalParameterService::CashStatementThreshold]);
        }

        $allowed = Money::compare($revenues, Money::normalize($threshold)) < 0;

        return $this->check($form === 'cash' && ! $allowed ? 'cash_form_not_allowed' : 'cash_threshold', $form === 'cash' && ! $allowed ? 'error' : 'info', [
            'revenues' => $revenues,
            'threshold' => Money::normalize($threshold),
            'cash_form_allowed' => $allowed,
            'source' => $this->parameters->find(LegalParameterService::CashStatementThreshold, $year)?->source_reference,
        ]);
    }

    /**
     * The share of the secondary activities against total revenues and total costs, within the limits of the year.
     *
     * @return array{code: string, severity: string, message: string, details: array<string, mixed>}
     */
    public function secondaryActivitiesCheck(int $year): array
    {
        $breakdown = $this->statements->revenueBreakdown($year);
        $revenueLimit = $this->parameters->value(LegalParameterService::SecondaryRevenueSharePercent, $year);
        $costLimit = $this->parameters->value(LegalParameterService::SecondaryCostSharePercent, $year);
        $revenueShare = $this->share($breakdown['secondary'], $breakdown['total']);
        $costShare = $this->share($breakdown['secondary'], $breakdown['costs']);
        $details = ['secondary_revenues' => $breakdown['secondary'], 'share_of_revenues' => $revenueShare, 'share_of_costs' => $costShare, 'revenue_limit' => $revenueLimit, 'cost_limit' => $costLimit];

        if ($revenueLimit === null && $costLimit === null) {
            return $this->check('secondary_limits_not_configured', 'info', $details);
        }

        $withinRevenue = $revenueLimit !== null && $revenueShare !== null && bccomp($revenueShare, $revenueLimit, 4) <= 0;
        $withinCosts = $costLimit !== null && $costShare !== null && bccomp($costShare, $costLimit, 4) <= 0;
        $none = Money::compare($breakdown['secondary'], '0') === 0;

        return $this->check($none || $withinRevenue || $withinCosts ? 'secondary_within_limits' : 'secondary_over_limits', $none || $withinRevenue || $withinCosts ? 'info' : 'warning', $details);
    }

    /**
     * Commercial income against non-commercial income, from the lines the company marked as commercial.
     *
     * @return array{code: string, severity: string, message: string, details: array<string, mixed>}
     */
    public function commercialPrevalenceCheck(int $year): array
    {
        $breakdown = $this->statements->revenueBreakdown($year);
        $prevails = Money::compare($breakdown['commercial'], $breakdown['non_commercial']) > 0;

        return $this->check($prevails ? 'commercial_income_prevails' : 'commercial_income_not_prevailing', $prevails ? 'warning' : 'info', [
            'commercial' => $breakdown['commercial'],
            'non_commercial' => $breakdown['non_commercial'],
        ]);
    }

    /**
     * The points of the mission report with the facts the system already knows and the texts written so far.
     *
     * @return list<array{point: int, heading: string, facts: array<string, mixed>, text: ?string}>
     */
    public function missionOutline(int $year): array
    {
        $answers = $this->missionAnswers($year);
        $from = CarbonImmutable::create($year)->startOfYear()->toDateString();
        $to = CarbonImmutable::create($year)->endOfYear()->toDateString();
        $facts = [
            1 => ['activities' => GeneralInterestActivity::query()->where('is_active', true)->orderBy('kind')->orderBy('reference')->get(['kind', 'reference', 'description'])->toArray()],
            2 => [
                'active_members' => Member::query()->where('status', 'active')->count(),
                'memberships_in_year' => Membership::query()->where('status', MembershipStatus::Active)->whereDate('valid_from', '<=', $to)->whereDate('valid_to', '>=', $from)->count(),
            ],
            12 => [
                'money' => Donation::query()->where('kind', Donation::Money)->whereBetween('received_on', [$from, $to])->pluck('amount')->reduce(static fn (string $total, mixed $amount): string => Money::add($total, Money::normalize((string) $amount)), '0.00'),
                'in_kind' => Donation::query()->where('kind', Donation::InKind)->whereBetween('received_on', [$from, $to])->pluck('amount')->reduce(static fn (string $total, mixed $amount): string => Money::add($total, Money::normalize((string) $amount)), '0.00'),
            ],
            13 => ['regular_volunteers' => Volunteer::query()->where('is_occasional', false)->activeInYear($year)->distinct()->count('member_id')],
            24 => ['occasional_campaigns' => Campaign::query()->where('kind', Campaign::Occasional)
                ->where(static fn ($query) => $query->whereNull('starts_on')->orWhereDate('starts_on', '<=', $to))
                ->where(static fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $from))
                ->get()->map(fn (Campaign $campaign): array => array_intersect_key($this->fundraising->campaignReport($campaign), array_flip(['campaign', 'donations', 'expenses', 'reimbursements', 'result'])))->all()],
        ];

        $outline = [];

        foreach (ThirdSectorScheme::MissionReportPoints as $point => $heading) {
            $outline[] = ['point' => $point, 'heading' => $heading, 'facts' => $facts[$point] ?? [], 'text' => $answers[(string) $point] ?? null];
        }

        return $outline;
    }

    /**
     * @param  array<string, ?string>  $answers  by point number
     */
    public function saveMissionAnswers(int $year, array $answers): MissionReport
    {
        $report = MissionReport::query()->firstOrNew(['year' => $year], ['answers' => []]);
        $merged = array_filter([...($report->answers ?? []), ...array_map(static fn (?string $text): ?string => $text === null ? null : trim($text), $answers)], static fn (?string $text): bool => $text !== null && $text !== '');
        $report->answers = $merged;
        $report->save();

        return $report;
    }

    /**
     * Freezes the statements of the year after the checks. Errors block the preparation.
     */
    public function prepare(int $year, string $form, ?string $userId): StatementFiling
    {
        $checks = $this->checks($year, $form);
        $errors = array_values(array_filter($checks, static fn (array $check): bool => $check['severity'] === 'error'));

        if ($errors !== []) {
            throw new AssociationException(__('association_finance.filing_blocked', ['checks' => implode(', ', array_column($errors, 'code'))]));
        }

        $snapshot = [
            'year' => $year,
            'form' => $form,
            'source' => ThirdSectorScheme::Source,
            'generated_at' => now()->toIso8601String(),
            'statements' => $form === 'ordinary'
                ? ['balance_sheet' => $this->statements->balanceSheet($year), 'management_report' => $this->statements->managementReport($year), 'mission_report' => $this->missionOutline($year)]
                : ['cash_report' => $this->statements->cashReport($year)],
            'checks' => $checks,
        ];

        return DB::transaction(static fn (): StatementFiling => StatementFiling::create([
            'year' => $year,
            'form' => $form,
            'status' => 'prepared',
            'snapshot' => $snapshot,
            'content_hash' => hash('sha256', (string) json_encode($snapshot)),
            'prepared_by' => $userId,
        ]));
    }

    public function markFiled(StatementFiling $filing, string $reference, ?string $userId): StatementFiling
    {
        if ($filing->status === 'filed') {
            throw AssociationException::conflict(__('association_finance.filing_already_filed'));
        }

        $filing->update(['status' => 'filed', 'filed_at' => now(), 'filed_by' => $userId, 'filing_reference' => $reference]);

        return $filing;
    }

    public function csv(StatementFiling $filing): string
    {
        $rows = [];

        foreach ($filing->snapshot['statements'] as $statement => $data) {
            foreach ($data['lines'] ?? [] as $line) {
                if (! is_array($line) || ! isset($line['code'])) {
                    continue;
                }

                $rows[] = [$statement, $line['code'], $line['label'], $line['current'], $line['previous']];
            }
        }

        return CsvFile::write(['statement', 'line', 'label', 'year', 'previous_year'], $rows);
    }

    public function printable(StatementFiling $filing): string
    {
        return $this->pdf->render('associations::pdf.statement', ['tenant' => $this->tenantContext->getTenant(), 'filing' => $filing]);
    }

    /**
     * @return array<string, string>
     */
    private function missionAnswers(int $year): array
    {
        return MissionReport::query()->where('year', $year)->first()?->answers ?? [];
    }

    private function share(string $part, string $whole): ?string
    {
        return Money::compare($whole, '0') <= 0 ? null : bcdiv(bcmul($part, '100', 4), $whole, 2);
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array{code: string, severity: string, message: string, details: array<string, mixed>}
     */
    private function check(string $code, string $severity, array $details): array
    {
        return ['code' => $code, 'severity' => $severity, 'message' => (string) __('association_finance.checks.'.$code), 'details' => $details];
    }
}

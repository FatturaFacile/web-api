<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Consolidation;

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\GeneralLedger\Models\ConsolidationAdjustment;
use Modules\GeneralLedger\Models\ConsolidationAdjustmentLine;
use Modules\GeneralLedger\Models\ConsolidationGroup;
use Modules\GeneralLedger\Models\ConsolidationRun;

/**
 * The goodwill schedule of a run, for audit: for every acquisition of the run that produced a goodwill, the amount per
 * fiscal year of the parent (straight line by whole months over the years of the group, impairments entered by the
 * user), the balance at the start and at the end of each year, and for the year of the run the charges to the period
 * end, which are the ones the run posted. The goodwill amount comes from the adjustments the run stored; the method, the
 * years, the acquisition date and the impairments from the inputs the run stored, so the schedule of an old run does
 * not change when the configuration does.
 *
 * Amortisation is counted in whole months inside each fiscal year, starting at the acquisition date, and never takes
 * the balance below zero; the number of years is an input of the group and no legal period is assumed. Reading it needs
 * the access to every member entity, like the run itself.
 */
final readonly class GoodwillScheduleService
{
    public function __construct(private ConsolidationAccessGuard $guard) {}

    /**
     * @return array<string, mixed>
     */
    public function forRun(User $user, ConsolidationRun $run): array
    {
        $group = ConsolidationGroup::withoutGlobalScopes()->where('tenant_id', $run->tenant_id)->findOrFail($run->group_id);
        $this->guard->assertCanReadGroup($user, $group);

        $inputs = $run->inputs_snapshot;
        $method = (string) ($inputs['settings'][1] ?? 'none');
        $years = isset($inputs['settings'][2]) ? (int) $inputs['settings'][2] : null;
        $names = collect($run->members_snapshot)->pluck('name', 'tenant_id');
        $goodwill = $this->goodwillOf($run, $group->syntheticCode('goodwill'));
        $acquisitions = [];

        foreach ($inputs['acquisitions'] ?? [] as $tenantId => $acquisition) {
            if (! isset($goodwill[$tenantId])) {
                continue;
            }

            $acquisitions[] = [
                'member_tenant_id' => (string) $tenantId,
                'name' => (string) ($names[$tenantId] ?? $tenantId),
                'acquisition_date' => $acquisition['acquisition_date'],
                'goodwill' => $goodwill[$tenantId],
                'rows' => $this->rows($run, $method, $years, $goodwill[$tenantId], $acquisition),
            ];
        }

        return [
            'run_id' => (string) $run->id,
            'currency' => $run->group_currency,
            'goodwill_method' => $method,
            'goodwill_amortisation_years' => $years,
            'acquisitions' => $acquisitions,
        ];
    }

    /**
     * @return array<string, string> Goodwill by member tenant, from the adjustments of the run
     */
    private function goodwillOf(ConsolidationRun $run, string $code): array
    {
        $amounts = [];
        $adjustments = ConsolidationAdjustment::withoutGlobalScopes()->where('run_id', $run->id)->where('tenant_id', $run->tenant_id)->where('adjustment_type', 'goodwill')->get();

        foreach ($adjustments as $adjustment) {
            $line = ConsolidationAdjustmentLine::withoutGlobalScopes()->where('adjustment_id', $adjustment->id)->where('account_code', $code)->first();

            if ($line !== null && ConsolidationMath::isPositive((string) $line->debit)) {
                $amounts[(string) $adjustment->member_tenant_id] = ConsolidationMath::norm((string) $line->debit);
            }
        }

        return $amounts;
    }

    /**
     * @param  array<string, mixed>  $acquisition
     * @return list<array<string, mixed>>
     */
    private function rows(ConsolidationRun $run, string $method, ?int $years, string $goodwill, array $acquisition): array
    {
        $date = CarbonImmutable::parse($acquisition['acquisition_date']);
        $runYearStart = CarbonImmutable::parse($run->year_start);
        $periodEnd = CarbonImmutable::parse($run->period_end);
        $months = $method === 'straight_line' && $years !== null && $years > 0 ? $years * 12 : 0;
        $start = $runYearStart;

        while ($start > $date) {
            $start = $start->subYearNoOverflow();
        }

        $rows = [];
        $balance = $goodwill;
        $elapsed = 0;

        for ($index = 0; $index < 102; $index++) {
            $end = $start->addYearNoOverflow()->subDay();
            $from = $date > $start ? $date : $start;
            $isRunYear = $start->equalTo($runYearStart);
            $yearMonths = $months > 0 ? max(0, min($months - $elapsed, $this->wholeMonths($from, $end->addDay()))) : 0;
            $amortisation = ConsolidationMath::min(ConsolidationMath::mul($goodwill, $months > 0 ? bcdiv((string) $yearMonths, (string) $months, 10) : '0'), $balance);
            $impairment = ConsolidationMath::zero();

            foreach ($acquisition['impairments'] ?? [] as $entered) {
                if ((int) $entered['fiscal_year'] === $start->year) {
                    $impairment = ConsolidationMath::add($impairment, (string) $entered['amount']);
                }
            }

            $room = ConsolidationMath::sub($balance, $amortisation);
            $impairment = ConsolidationMath::min($impairment, ConsolidationMath::isPositive($room) ? $room : ConsolidationMath::zero());
            $closing = ConsolidationMath::sub($room, $impairment);
            $row = [
                'fiscal_year' => $start->year,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'opening_balance' => $balance,
                'amortisation' => $amortisation,
                'impairment' => $impairment,
                'closing_balance' => $closing,
                'is_run_year' => $isRunYear,
            ];

            if ($isRunYear) {
                $toDate = $months > 0 ? max(0, min($months - $elapsed, $this->wholeMonths($from, $periodEnd->addDay()))) : 0;
                $row['amortisation_to_period_end'] = ConsolidationMath::mul($goodwill, $months > 0 ? bcdiv((string) $toDate, (string) $months, 10) : '0');
                $row['impairment_to_period_end'] = $impairment;
            }

            $rows[] = $row;
            $balance = $closing;
            $elapsed += $yearMonths;
            $start = $end->addDay();

            $finished = ConsolidationMath::isZero($balance) || ($months > 0 ? $elapsed >= $months : $start > $runYearStart);

            if ($finished && $start > $runYearStart) {
                break;
            }
        }

        return $rows;
    }

    private function wholeMonths(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return $to <= $from ? 0 : (int) floor($from->diffInMonths($to));
    }
}

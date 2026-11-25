<?php

declare(strict_types=1);

namespace Modules\Budgeting\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Budgeting\Contracts\LedgerLookup;
use Modules\Budgeting\Models\BudgetAuditEvent;
use Modules\Budgeting\Models\BudgetLine;
use Modules\Budgeting\Models\BudgetVersion;

/**
 * The version family of a fiscal year around the approved original budget: revised budgets (a formal revision of an
 * approved budget, never an edit of it), scenarios (best case, worst case or custom, scaled from any version), transfers
 * between lines of a draft, and the roll forward of a rolling forecast to the next window of twelve months.
 */
final readonly class RevisionService
{
    public function __construct(private VersionService $versions, private LineService $lines, private LedgerLookup $ledger, private AuditTrail $audit) {}

    /**
     * A draft revised budget copied from an approved or locked original or revised budget.
     *
     * @param  array<string, mixed>  $data
     */
    public function revise(BudgetVersion $baseline, array $data, ?string $userId): BudgetVersion
    {
        if (! $baseline->isFormalBudget() || ! in_array($baseline->status, [BudgetVersion::Approved, BudgetVersion::Locked], true)) {
            throw ValidationException::withMessages(['status' => [__('budgeting_errors.revision.baseline_not_approved')]]);
        }

        $open = BudgetVersion::query()
            ->where('tenant_id', $baseline->tenant_id)
            ->where('fiscal_year', $baseline->fiscal_year)
            ->where('version_type', BudgetVersion::Revised)
            ->whereIn('status', [BudgetVersion::Draft, BudgetVersion::Submitted])
            ->exists();

        if ($open) {
            throw ValidationException::withMessages(['status' => [__('budgeting_errors.revision.already_open')]]);
        }

        $number = 1 + (int) BudgetVersion::query()->where('tenant_id', $baseline->tenant_id)->where('fiscal_year', $baseline->fiscal_year)->where('version_type', BudgetVersion::Revised)->max('revision_number');

        return DB::transaction(function () use ($baseline, $data, $userId, $number): BudgetVersion {
            $revised = $this->versions->copy($baseline, [
                'name' => $data['name'] ?? $baseline->name.' - '.__('budgeting_errors.revision.suffix', ['number' => $number]),
                'description' => $data['description'] ?? null,
                'version_type' => BudgetVersion::Revised,
                'revision_number' => $number,
                'revision_reason' => $data['reason'],
            ]);

            $this->audit->record($revised->tenant_id, BudgetAuditEvent::SubjectVersion, $revised->id, 'revise', $userId, $baseline->status, $revised->status, $data['reason'], ['baseline_id' => $baseline->id, 'revision_number' => $number]);

            return $revised;
        });
    }

    /**
     * A draft scenario: a copy of any version with every amount scaled by the adjustment (-10 gives 90 percent).
     *
     * @param  array<string, mixed>  $data
     */
    public function scenario(BudgetVersion $base, array $data, ?string $userId): BudgetVersion
    {
        $factor = bcadd('1', bcdiv((string) ($data['adjustment_percent'] ?? '0'), '100', 8), 8);

        if (bccomp($factor, '0', 8) < 0) {
            throw ValidationException::withMessages(['adjustment_percent' => [__('budgeting_errors.revision.adjustment_range')]]);
        }

        return DB::transaction(function () use ($base, $data, $userId, $factor): BudgetVersion {
            $scenario = $this->versions->copy($base, [
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'version_type' => BudgetVersion::Scenario,
                'scenario_kind' => $data['scenario_kind'],
            ]);

            if ($factor !== '1.00000000') {
                BudgetLine::query()->where('version_id', $scenario->id)->get()->each(function (BudgetLine $line) use ($factor): void {
                    $line->update(['amount' => bcround(bcmul((string) $line->amount, $factor, 6), 2)]);
                });
            }

            $this->audit->record($scenario->tenant_id, BudgetAuditEvent::SubjectVersion, $scenario->id, 'scenario', $userId, null, $scenario->status, null, ['base_id' => $base->id, 'kind' => $data['scenario_kind'], 'adjustment_percent' => (string) ($data['adjustment_percent'] ?? '0')]);

            return $scenario->refresh();
        });
    }

    /**
     * Moves an amount from one line of a draft version to another in the same period: the total stays the same.
     *
     * @param  array<string, mixed>  $data
     */
    public function transfer(BudgetVersion $version, array $data, ?string $userId): void
    {
        $this->versions->assertDraft($version);
        $amount = bcadd((string) $data['amount'], '0', 2);
        $period = (int) $data['period'];
        $from = $data['from'];
        $to = $data['to'];

        if ($from['account_id'] === $to['account_id'] && ($from['cost_center_id'] ?? null) === ($to['cost_center_id'] ?? null) && ($from['project_id'] ?? null) === ($to['project_id'] ?? null)) {
            throw ValidationException::withMessages(['to' => [__('budgeting_errors.revision.transfer_same')]]);
        }

        DB::transaction(function () use ($version, $from, $to, $amount, $period, $data, $userId): void {
            $this->lines->assertDimensions($version, [...$from, 'period' => $period]);
            $this->lines->assertDimensions($version, [...$to, 'period' => $period]);

            $source = $this->line($version, $from, $period);

            if ($source === null || bccomp((string) $source->amount, $amount, 2) < 0) {
                throw ValidationException::withMessages(['amount' => [__('budgeting_errors.revision.transfer_insufficient')]]);
            }

            $target = $this->line($version, $to, $period);
            $this->lines->upsert($version, $from, $period, bcsub((string) $source->amount, $amount, 2), BudgetLine::Manual, null, $source->notes, $source->quantity, $source->unit_price);
            $this->lines->upsert($version, $to, $period, bcadd((string) ($target->amount ?? '0'), $amount, 2), BudgetLine::Manual, null, $target?->notes, $target?->quantity, $target?->unit_price);

            $this->audit->record($version->tenant_id, BudgetAuditEvent::SubjectVersion, $version->id, 'transfer', $userId, null, null, $data['comment'] ?? null, ['from' => $from, 'to' => $to, 'period' => $period, 'amount' => $amount]);
        });
    }

    /**
     * Moves a rolling forecast one or more months ahead: a new draft of twelve months that starts later. The months
     * already closed hold the actuals of the ledger, the months ahead keep the forecast of the base (the months at the
     * end of the new window repeat the same month of the base window).
     *
     * @param  array<string, mixed>  $data
     */
    public function rollForward(BudgetVersion $base, array $data, ?string $userId): BudgetVersion
    {
        if ($base->version_type !== BudgetVersion::RollingForecast || ! in_array($base->status, [BudgetVersion::Approved, BudgetVersion::Locked], true)) {
            throw ValidationException::withMessages(['status' => [__('budgeting_errors.revision.roll_base')]]);
        }

        $shift = max(1, min(BudgetPeriods::Count, (int) ($data['shift_months'] ?? 1)));
        $factor = bcadd('1', bcdiv((string) ($data['adjustment_percent'] ?? '0'), '100', 8), 8);
        $start = Carbon::parse($base->year_start)->startOfMonth()->addMonthsNoOverflow($shift);
        $name = (string) ($data['name'] ?? ($base->name.' '.$start->format('Y-m')));

        $taken = BudgetVersion::query()->where('tenant_id', $base->tenant_id)->where('fiscal_year', $start->year)->where('name', $name)->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => [__('budgeting_errors.version.name_taken', ['year' => $start->year])]]);
        }

        $baseAmounts = [];

        foreach (BudgetLine::query()->where('version_id', $base->id)->get() as $line) {
            $key = $line->account_id."\0".($line->cost_center_id ?? '')."\0".($line->project_id ?? '');
            $baseAmounts[$key] ??= ['account_id' => $line->account_id, 'cost_center_id' => $line->cost_center_id, 'project_id' => $line->project_id, 'periods' => []];
            $baseAmounts[$key]['periods'][$line->period] = bcadd($baseAmounts[$key]['periods'][$line->period] ?? '0', (string) $line->amount, 2);
        }

        $elapsed = max(0, min(BudgetPeriods::Count, (int) round($start->diffInMonths(now()->startOfMonth(), false))));
        $actuals = $elapsed > 0
            ? $this->actualsByMonth($base->tenant_id, $start, $start->copy()->addMonthsNoOverflow($elapsed - 1)->endOfMonth()->startOfDay())
            : [];

        return DB::transaction(function () use ($base, $start, $name, $shift, $factor, $baseAmounts, $elapsed, $actuals, $userId): BudgetVersion {
            $rolled = BudgetVersion::create([
                'tenant_id' => $base->tenant_id,
                'name' => $name,
                'description' => $base->description,
                'fiscal_year' => $start->year,
                'year_start' => $start->toDateString(),
                'version_type' => BudgetVersion::RollingForecast,
                'status' => BudgetVersion::Draft,
                'currency' => $base->currency,
                'granularity' => BudgetVersion::Monthly,
                'base_version_id' => $base->id,
                'reforecast_from_period' => $elapsed + 1,
                'rolling_horizon_months' => BudgetPeriods::Count,
                'seasonal_weights' => $base->seasonal_weights,
            ]);

            foreach ($baseAmounts as $key => $combo) {
                for ($period = 1; $period <= BudgetPeriods::Count; $period++) {
                    if ($period <= $elapsed) {
                        $month = $start->copy()->addMonthsNoOverflow($period - 1)->format('Y-m');
                        $amount = $actuals[$key][$month] ?? '0.00';
                        $source = BudgetLine::Actual;
                    } else {
                        $origin = $period + $shift;
                        $origin = $origin > BudgetPeriods::Count ? $origin - BudgetPeriods::Count : $origin;
                        $amount = bcround(bcmul($combo['periods'][$origin] ?? '0', $factor, 6), 2);
                        $source = BudgetLine::Reforecast;
                    }

                    $this->lines->upsert($rolled, $combo, $period, $amount, $source, null, null);
                }
            }

            $this->audit->record($rolled->tenant_id, BudgetAuditEvent::SubjectVersion, $rolled->id, 'roll_forward', $userId, null, $rolled->status, null, ['base_id' => $base->id, 'shift_months' => $shift, 'closed_periods' => $elapsed]);

            return $rolled->refresh();
        });
    }

    /**
     * @return array<string, array<string, string>> combo key => month (Y-m) => amount
     */
    private function actualsByMonth(string $tenantId, Carbon $from, Carbon $to): array
    {
        $result = [];

        foreach ($this->ledger->monthlyActuals($tenantId, $from, $to) as $row) {
            $key = $row['account_id']."\0".($row['cost_center_id'] ?? '')."\0".($row['project_id'] ?? '');
            $result[$key][$row['month']] = bcadd($result[$key][$row['month']] ?? '0', $row['amount'], 2);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $key
     */
    private function line(BudgetVersion $version, array $key, int $period): ?BudgetLine
    {
        return BudgetLine::query()
            ->where('version_id', $version->id)
            ->where('account_id', $key['account_id'])
            ->where('period', $period)
            ->when(empty($key['cost_center_id']), static fn ($query) => $query->whereNull('cost_center_id'), static fn ($query) => $query->where('cost_center_id', $key['cost_center_id']))
            ->when(empty($key['project_id']), static fn ($query) => $query->whereNull('project_id'), static fn ($query) => $query->where('project_id', $key['project_id']))
            ->first();
    }
}

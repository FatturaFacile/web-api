<?php

declare(strict_types=1);

namespace Modules\Budgeting\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Budgeting\Contracts\LedgerLookup;
use Modules\Budgeting\Models\BudgetLine;
use Modules\Budgeting\Models\BudgetVersion;

/**
 * Rolling forecast: a new draft version of an approved or locked version where the closed periods hold the actuals of
 * the ledger and the open periods are re-forecast. Mode keep_annual_target spreads what is left of the annual amount
 * (annual budget less the actuals so far, never below zero) over the open periods in the proportion of the base
 * version; mode keep_remaining keeps the open periods of the base version. An adjustment percentage scales the open
 * periods.
 */
final readonly class ReforecastService
{
    public const string KeepAnnualTarget = 'keep_annual_target';

    public const string KeepRemaining = 'keep_remaining';

    public const array Modes = [self::KeepAnnualTarget, self::KeepRemaining];

    public function __construct(private LedgerLookup $ledger, private VersionService $versions) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function reforecast(BudgetVersion $base, array $data): BudgetVersion
    {
        if (! in_array($base->status, [BudgetVersion::Approved, BudgetVersion::Locked], true)) {
            throw ValidationException::withMessages(['status' => [__('budgeting_errors.reforecast.base_not_approved')]]);
        }

        $from = (int) $data['from_period'];
        $mode = (string) ($data['mode'] ?? self::KeepAnnualTarget);
        $factor = bcadd('1', bcdiv((string) ($data['adjustment_percent'] ?? '0'), '100', 8), 8);
        $combos = $this->combos($base);

        if ($combos === []) {
            throw ValidationException::withMessages(['status' => [__('budgeting_errors.reforecast.no_lines')]]);
        }

        $actuals = $from > 1 ? $this->actualsByCombo($base, $from - 1) : [];

        return DB::transaction(function () use ($base, $data, $from, $mode, $factor, $combos, $actuals): BudgetVersion {
            $forecast = $this->versions->copy($base, ['name' => $data['name'], 'description' => $data['description'] ?? null, 'version_type' => BudgetVersion::RollingForecast]);
            $forecast->reforecast_from_period = $from;
            $forecast->save();
            BudgetLine::query()->where('version_id', $forecast->id)->get()->each->delete();
            $forecast->drivers()->get()->each->delete();

            foreach ($combos as $key => $combo) {
                $baseAmounts = $combo['amounts'];
                $actualAmounts = $actuals[$key] ?? [];
                $closedTotal = '0';

                for ($period = 1; $period < $from; $period++) {
                    $actual = $actualAmounts[$period] ?? '0.00';
                    $closedTotal = bcadd($closedTotal, $actual, 2);
                    $this->write($forecast, $combo, $period, $actual, BudgetLine::Actual);
                }

                $openPeriods = $from <= BudgetPeriods::Count ? range($from, BudgetPeriods::Count) : [];
                $open = $this->openAmounts($baseAmounts, $openPeriods, $mode, $closedTotal, $factor);

                foreach ($open as $period => $amount) {
                    $this->write($forecast, $combo, $period, $amount, BudgetLine::Reforecast);
                }
            }

            return $forecast->refresh();
        });
    }

    /**
     * @param  array<int, string>  $baseAmounts
     * @param  list<int>  $openPeriods
     * @return array<int, string>
     */
    private function openAmounts(array $baseAmounts, array $openPeriods, string $mode, string $closedTotal, string $factor): array
    {
        $result = [];

        if ($openPeriods === []) {
            return $result;
        }

        if ($mode === self::KeepRemaining) {
            foreach ($openPeriods as $period) {
                $result[$period] = bcround(bcmul($baseAmounts[$period] ?? '0', $factor, 6), 2);
            }

            return $result;
        }

        $annual = array_reduce($baseAmounts, static fn (string $carry, string $amount): string => bcadd($carry, $amount, 2), '0');
        $remaining = bcsub($annual, $closedTotal, 2);
        $remaining = bccomp($remaining, '0', 2) < 0 ? '0.00' : $remaining;
        $remaining = bcround(bcmul($remaining, $factor, 6), 2);
        $weights = array_map(static fn (int $period): string => $baseAmounts[$period] ?? '0', $openPeriods);
        $weightSum = array_reduce($weights, static fn (string $carry, string $weight): string => bcadd($carry, $weight, 2), '0');
        $allocated = '0';

        foreach ($openPeriods as $index => $period) {
            $isLast = $index === count($openPeriods) - 1;

            if ($isLast) {
                $amount = bcsub($remaining, $allocated, 2);
            } elseif (bccomp($weightSum, '0', 2) > 0) {
                $amount = bcround(bcdiv(bcmul($remaining, $weights[$index], 8), $weightSum, 8), 2);
            } else {
                $amount = bcround(bcdiv($remaining, (string) count($openPeriods), 8), 2);
            }

            $allocated = bcadd($allocated, $amount, 2);
            $result[$period] = $amount;
        }

        return $result;
    }

    /**
     * The budgeted combinations of account, cost centre and project of a version with their amount per period.
     *
     * @return array<string, array{account_id: string, cost_center_id: string|null, project_id: string|null, amounts: array<int, string>}>
     */
    private function combos(BudgetVersion $version): array
    {
        $combos = [];

        foreach (BudgetLine::query()->where('version_id', $version->id)->get() as $line) {
            $key = $this->key($line->account_id, $line->cost_center_id, $line->project_id);
            $combos[$key] ??= ['account_id' => $line->account_id, 'cost_center_id' => $line->cost_center_id, 'project_id' => $line->project_id, 'amounts' => []];
            $combos[$key]['amounts'][$line->period] = bcadd($combos[$key]['amounts'][$line->period] ?? '0', (string) $line->amount, 2);
        }

        return $combos;
    }

    /**
     * Actuals per budgeted combination and period up to a period (inclusive).
     *
     * @return array<string, array<int, string>>
     */
    private function actualsByCombo(BudgetVersion $version, int $throughPeriod): array
    {
        $result = [];
        $rows = $this->ledger->monthlyActuals($version->tenant_id, BudgetPeriods::periodStart($version, 1), BudgetPeriods::periodEnd($version, $throughPeriod));

        foreach ($rows as $row) {
            $period = BudgetPeriods::periodOfMonth($version, $row['month']);

            if ($period === null) {
                continue;
            }

            $key = $this->key($row['account_id'], $row['cost_center_id'], $row['project_id']);
            $result[$key][$period] = bcadd($result[$key][$period] ?? '0', $row['amount'], 2);
        }

        return $result;
    }

    /**
     * @param  array{account_id: string, cost_center_id: string|null, project_id: string|null, amounts: array<int, string>}  $combo
     */
    private function write(BudgetVersion $forecast, array $combo, int $period, string $amount, string $source): void
    {
        BudgetLine::create([
            'tenant_id' => $forecast->tenant_id,
            'version_id' => $forecast->id,
            'account_id' => $combo['account_id'],
            'cost_center_id' => $combo['cost_center_id'],
            'project_id' => $combo['project_id'],
            'period' => $period,
            'amount' => $amount,
            'source' => $source,
        ]);
    }

    private function key(string $accountId, ?string $costCenterId, ?string $projectId): string
    {
        return $accountId."\0".($costCenterId ?? '')."\0".($projectId ?? '');
    }
}

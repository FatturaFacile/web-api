<?php

declare(strict_types=1);

namespace Modules\Budgeting\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Budgeting\Contracts\LedgerLookup;
use Modules\Budgeting\Models\BudgetAuditEvent;
use Modules\Budgeting\Models\BudgetLine;
use Modules\Budgeting\Models\BudgetVersion;

/**
 * The multidimensional grid of a version: account x cost centre x project against the periods. A monthly version shows
 * twelve columns, a quarterly one four; the lines are always stored by month, so a quarterly amount is spread evenly
 * over its three months (the cents of the division go to the last month) and a quarter is the sum of its months.
 */
final readonly class GridService
{
    public const int Quarters = 4;

    public function __construct(private LineService $lines, private VersionService $versions, private LedgerLookup $ledger, private AuditTrail $audit) {}

    public function slotCount(BudgetVersion $version): int
    {
        return $version->isQuarterly() ? self::Quarters : BudgetPeriods::Count;
    }

    /**
     * @return list<array{slot: int, label: string, from_period: int, to_period: int}>
     */
    public function slots(BudgetVersion $version): array
    {
        $slots = [];

        foreach (range(1, $this->slotCount($version)) as $slot) {
            $from = $version->isQuarterly() ? ($slot - 1) * 3 + 1 : $slot;
            $to = $version->isQuarterly() ? $from + 2 : $slot;
            $slots[] = ['slot' => $slot, 'label' => $version->isQuarterly() ? 'Q'.$slot : BudgetPeriods::label($version, $slot), 'from_period' => $from, 'to_period' => $to];
        }

        return $slots;
    }

    /**
     * @return array{version_id: string, granularity: string, slots: list<array<string, mixed>>, rows: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function grid(BudgetVersion $version): array
    {
        $slots = $this->slots($version);
        $slotOfPeriod = [];

        foreach ($slots as $slot) {
            foreach (range($slot['from_period'], $slot['to_period']) as $period) {
                $slotOfPeriod[$period] = $slot['slot'];
            }
        }

        $rows = [];

        foreach (BudgetLine::query()->where('version_id', $version->id)->get() as $line) {
            $key = $line->account_id."\0".($line->cost_center_id ?? '')."\0".($line->project_id ?? '');
            $rows[$key] ??= ['account_id' => $line->account_id, 'cost_center_id' => $line->cost_center_id, 'project_id' => $line->project_id, 'cells' => [], 'total' => '0.00'];
            $slot = $slotOfPeriod[$line->period];
            $cell = $rows[$key]['cells'][$slot] ?? ['amount' => '0.00', 'quantity' => null, 'unit_price' => null];
            $cell['amount'] = bcadd($cell['amount'], (string) $line->amount, 2);

            if ($line->quantity !== null) {
                $cell['quantity'] = bcadd($cell['quantity'] ?? '0', (string) $line->quantity, 3);
            }

            if ($line->unit_price !== null) {
                $cell['unit_price'] = (string) $line->unit_price;
            }

            $rows[$key]['cells'][$slot] = $cell;
            $rows[$key]['total'] = bcadd($rows[$key]['total'], (string) $line->amount, 2);
        }

        $accounts = $this->ledger->accounts($version->tenant_id, array_values(array_unique(array_column($rows, 'account_id'))));
        $costCenters = $this->ledger->costCenters($version->tenant_id, array_values(array_filter(array_unique(array_column($rows, 'cost_center_id')))));
        $projects = $this->ledger->projects($version->tenant_id, array_values(array_filter(array_unique(array_column($rows, 'project_id')))));
        $totals = ['total' => '0.00', 'slots' => []];
        $output = [];

        foreach ($rows as $key => $row) {
            $totals['total'] = bcadd($totals['total'], $row['total'], 2);

            foreach ($row['cells'] as $slot => $cell) {
                $totals['slots'][$slot] = bcadd($totals['slots'][$slot] ?? '0', $cell['amount'], 2);
            }

            $output[] = [
                'key' => md5($key),
                'account_id' => $row['account_id'],
                'account_code' => $accounts[$row['account_id']]['code'] ?? null,
                'account_name' => $accounts[$row['account_id']]['name'] ?? null,
                'cost_center_id' => $row['cost_center_id'],
                'cost_center_code' => $costCenters[$row['cost_center_id']]['code'] ?? null,
                'cost_center_name' => $costCenters[$row['cost_center_id']]['name'] ?? null,
                'project_id' => $row['project_id'],
                'project_code' => $projects[$row['project_id']]['code'] ?? null,
                'project_name' => $projects[$row['project_id']]['name'] ?? null,
                'cells' => (object) $row['cells'],
                'total' => $row['total'],
            ];
        }

        usort($output, static fn (array $left, array $right): int => strcmp((string) $left['account_code'].$left['cost_center_code'].$left['project_code'], (string) $right['account_code'].$right['cost_center_code'].$right['project_code']));

        return ['version_id' => $version->id, 'granularity' => $version->granularity, 'slots' => $slots, 'rows' => $output, 'totals' => ['total' => $totals['total'], 'slots' => (object) $totals['slots']]];
    }

    /**
     * Saves cells of the grid of a draft version.
     *
     * @param  list<array<string, mixed>>  $cells
     * @return array{saved: int}
     */
    public function save(BudgetVersion $version, array $cells, ?string $userId): array
    {
        $this->versions->assertDraft($version);
        $slotCount = $this->slotCount($version);

        return DB::transaction(function () use ($version, $cells, $slotCount, $userId): array {
            $changes = [];

            foreach ($cells as $cell) {
                $slot = (int) $cell['slot'];

                if ($slot < 1 || $slot > $slotCount) {
                    throw ValidationException::withMessages(['cells' => [__('budgeting_errors.grid.slot_range', ['max' => $slotCount])]]);
                }

                $this->lines->assertDimensions($version, [...$cell, 'period' => $version->isQuarterly() ? ($slot - 1) * 3 + 1 : $slot]);

                $periods = $version->isQuarterly() ? [($slot - 1) * 3 + 1, ($slot - 1) * 3 + 2, ($slot - 1) * 3 + 3] : [$slot];
                $amounts = $this->split(bcadd((string) $cell['amount'], '0', 2), count($periods), 2);
                $quantities = isset($cell['quantity']) ? $this->split(bcadd((string) $cell['quantity'], '0', 3), count($periods), 3) : array_fill(0, count($periods), null);

                foreach ($periods as $index => $period) {
                    $before = BudgetLine::query()->where('version_id', $version->id)->where('account_id', $cell['account_id'])->where('period', $period)
                        ->when(empty($cell['cost_center_id']), static fn ($query) => $query->whereNull('cost_center_id'), static fn ($query) => $query->where('cost_center_id', $cell['cost_center_id']))
                        ->when(empty($cell['project_id']), static fn ($query) => $query->whereNull('project_id'), static fn ($query) => $query->where('project_id', $cell['project_id']))
                        ->value('amount');

                    $this->lines->upsert($version, $cell, $period, $amounts[$index], BudgetLine::Manual, null, $cell['notes'] ?? null, $quantities[$index], isset($cell['unit_price']) ? (string) $cell['unit_price'] : null);
                    $changes[] = ['account_id' => $cell['account_id'], 'cost_center_id' => $cell['cost_center_id'] ?? null, 'project_id' => $cell['project_id'] ?? null, 'period' => $period, 'before' => $before === null ? null : (string) $before, 'after' => $amounts[$index]];
                }
            }

            $this->audit->record($version->tenant_id, BudgetAuditEvent::SubjectVersion, $version->id, 'grid_saved', $userId, null, null, null, ['cells' => count($cells), 'changes' => array_slice($changes, 0, 300)]);

            return ['saved' => count($cells)];
        });
    }

    /**
     * Splits an amount in equal parts at the given scale; the remainder of the division goes to the last part.
     *
     * @return list<string>
     */
    public function split(string $amount, int $parts, int $scale): array
    {
        if ($parts === 1) {
            return [$amount];
        }

        $share = bcdiv($amount, (string) $parts, $scale);
        $result = array_fill(0, $parts - 1, $share);
        $result[] = bcsub($amount, bcmul($share, (string) ($parts - 1), $scale), $scale);

        return $result;
    }
}

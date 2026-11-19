<?php

declare(strict_types=1);

namespace Modules\Controlling\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Controlling\Models\AnalyticalLedgerEntry;
use Modules\Controlling\Models\CostAllocationRule;
use Modules\Controlling\Models\CostAllocationRun;
use Modules\Controlling\Support\Decimal;
use RuntimeException;

/**
 * Driver-based overhead reallocation (ribaltamenti). Rules run in step order: the pool of a source center is its own
 * direct cost of the period plus what earlier steps passed to it, and it is split over the recipients (centers or jobs)
 * by the driver weights measured on the period actuals. Every transfer is a pair of ledger lines (credit on the source,
 * debit on the recipient), so the run balances to zero; the penny residual goes to the largest recipient. A run can be
 * previewed without writing, posted, and reversed with mirror lines.
 */
final class CostAllocationEngineService
{
    private const array CostNatures = ['direct_material', 'direct_labor', 'variable_overhead', 'fixed_overhead', 'opex'];

    public function __construct(private readonly MarginFactSynchronizer $facts) {}

    /**
     * The transfers a run of the period would post, without writing anything.
     *
     * @return array{transfers: list<array<string, mixed>>, skipped: list<array<string, mixed>>, total_allocated: string}
     */
    public function preview(string $tenantId, int $year, int $month): array
    {
        $rules = CostAllocationRule::query()->where('tenant_id', $tenantId)->where('is_active', true)->orderBy('step_order')->orderBy('created_at')->orderBy('id')->get();
        $incoming = [];
        $transfers = [];
        $skipped = [];
        $total = Decimal::norm(0);

        foreach ($rules as $rule) {
            $source = (string) $rule->source_cost_center_id;
            $pool = Decimal::add($this->directCost($tenantId, $year, $month, 'cost_center_id', $source), $incoming[$source] ?? 0);
            if (Decimal::cmp($pool, 0) <= 0) {
                $skipped[] = ['rule_id' => $rule->id, 'reason' => 'empty_pool'];

                continue;
            }
            $weights = $this->weights($tenantId, $year, $month, $rule);
            if (Decimal::isZero(array_reduce($weights, static fn (string $carry, array $w): string => Decimal::add($carry, $w['weight']), '0'))) {
                $skipped[] = ['rule_id' => $rule->id, 'reason' => 'no_driver_weight'];

                continue;
            }
            $shares = Decimal::spread($pool, array_map(static fn (array $w): string => $w['weight'], $weights));
            foreach ($weights as $key => $recipient) {
                if (Decimal::isZero($shares[$key])) {
                    continue;
                }
                $transfers[] = [
                    'rule_id' => $rule->id,
                    'step_order' => $rule->step_order,
                    'source_cost_center_id' => $source,
                    'recipient_type' => $recipient['type'],
                    'recipient_id' => $recipient['id'],
                    'driver_type' => $rule->driver_type,
                    'driver_weight' => $recipient['weight'],
                    'amount' => $shares[$key],
                ];
                $total = Decimal::add($total, $shares[$key]);
                if ($recipient['type'] === 'cost_center') {
                    $incoming[$recipient['id']] = Decimal::add($incoming[$recipient['id']] ?? 0, $shares[$key]);
                }
            }
        }

        return ['transfers' => $transfers, 'skipped' => $skipped, 'total_allocated' => $total];
    }

    /**
     * Post the allocation of the period.
     */
    public function execute(string $tenantId, int $year, int $month, ?string $userId = null): CostAllocationRun
    {
        $preview = $this->preview($tenantId, $year, $month);

        $run = DB::transaction(function () use ($tenantId, $year, $month, $userId, $preview): CostAllocationRun {
            $open = CostAllocationRun::query()->where('tenant_id', $tenantId)->where('fiscal_year', $year)->where('fiscal_month', $month)->where('status', CostAllocationRun::Posted)->lockForUpdate()->exists();
            if ($open) {
                throw new RuntimeException('An allocation run is already posted for this period: reverse it first.');
            }
            if ($preview['transfers'] === []) {
                throw new RuntimeException('Nothing to allocate for this period.');
            }
            $run = CostAllocationRun::create([
                'tenant_id' => $tenantId, 'fiscal_year' => $year, 'fiscal_month' => $month, 'status' => CostAllocationRun::Posted,
                'total_allocated_amount' => $preview['total_allocated'], 'executed_by' => $userId, 'executed_at' => now(),
            ]);
            $date = sprintf('%04d-%02d-01', $year, $month);
            $date = date('Y-m-t', (int) strtotime($date));
            $now = now();
            $rows = [];
            foreach ($preview['transfers'] as $transfer) {
                $common = ['tenant_id' => $tenantId, 'posting_date' => $date, 'fiscal_year' => $year, 'fiscal_month' => $month, 'source_type' => 'overhead_allocation', 'source_id' => $transfer['rule_id'], 'nature_type' => 'fixed_overhead', 'quantity' => '0.0000', 'is_allocated' => true, 'allocation_run_id' => $run->id, 'created_at' => $now, 'updated_at' => $now];
                $rows[] = $common + ['id' => (string) Str::uuid7(), 'cost_center_id' => $transfer['source_cost_center_id'], 'job_id' => null, 'amount' => Decimal::neg($transfer['amount']), 'description' => 'Overhead allocation out ('.$transfer['driver_type'].')'];
                $rows[] = $common + [
                    'id' => (string) Str::uuid7(),
                    'cost_center_id' => $transfer['recipient_type'] === 'cost_center' ? $transfer['recipient_id'] : null,
                    'job_id' => $transfer['recipient_type'] === 'job' ? $transfer['recipient_id'] : null,
                    'amount' => $transfer['amount'], 'description' => 'Overhead allocation in ('.$transfer['driver_type'].')',
                ];
            }
            $this->insert($rows);

            return $run;
        });

        $this->facts->syncOverheads($tenantId, $year, $month);

        return $run;
    }

    /**
     * Reverse a posted run with mirror lines, so that the audit trail keeps both movements.
     */
    public function reverse(string $tenantId, string $runId): CostAllocationRun
    {
        $run = DB::transaction(function () use ($tenantId, $runId): CostAllocationRun {
            $run = CostAllocationRun::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($runId);
            if ($run->status !== CostAllocationRun::Posted) {
                throw new RuntimeException('Only a posted allocation run can be reversed.');
            }
            $now = now();
            $rows = [];
            foreach (AnalyticalLedgerEntry::query()->where('tenant_id', $tenantId)->where('allocation_run_id', $run->id)->get() as $entry) {
                $rows[] = [
                    'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'posting_date' => $entry->posting_date->toDateString(), 'fiscal_year' => $entry->fiscal_year, 'fiscal_month' => $entry->fiscal_month,
                    'source_type' => 'overhead_allocation', 'source_id' => $entry->source_id, 'nature_type' => $entry->nature_type, 'cost_center_id' => $entry->cost_center_id, 'job_id' => $entry->job_id,
                    'quantity' => '0.0000', 'is_allocated' => true, 'allocation_run_id' => $run->id, 'amount' => Decimal::neg($entry->amount), 'description' => 'Reversal of '.$entry->description,
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
            $this->insert($rows);
            $run->update(['status' => CostAllocationRun::Reversed, 'reversed_at' => $now]);

            return $run;
        });

        $this->facts->syncOverheads($tenantId, (int) $run->fiscal_year, (int) $run->fiscal_month);

        return $run;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function insert(array $rows): void
    {
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('analytical_ledger_entries')->insert(array_map(static function (array $r): array {
                ksort($r);

                return $r;
            }, $chunk));
        }
    }

    /**
     * Driver weight of every recipient of the rule, measured on the actuals of the period.
     *
     * @return array<int, array{type: string, id: string, weight: string}>
     */
    private function weights(string $tenantId, int $year, int $month, CostAllocationRule $rule): array
    {
        $weights = [];
        foreach ((array) $rule->recipients as $recipient) {
            $type = (string) ($recipient['type'] ?? 'cost_center');
            $id = (string) ($recipient['id'] ?? '');
            $column = $type === 'job' ? 'job_id' : 'cost_center_id';
            $weight = match ($rule->driver_type) {
                'direct_revenue_share' => $this->measure($tenantId, $year, $month, $column, $id, ['revenue'], 'amount'),
                'direct_labor_hours' => $this->measure($tenantId, $year, $month, $column, $id, ['direct_labor'], 'quantity'),
                'direct_cogs_share' => $this->measure($tenantId, $year, $month, $column, $id, ['direct_material', 'direct_labor'], 'amount'),
                default => Decimal::norm($recipient['weight'] ?? 0),
            };
            $weights[] = ['type' => $type, 'id' => $id, 'weight' => Decimal::cmp($weight, 0) > 0 ? $weight : '0.0000'];
        }

        return $weights;
    }

    private function directCost(string $tenantId, int $year, int $month, string $column, string $id): string
    {
        return $this->measure($tenantId, $year, $month, $column, $id, self::CostNatures, 'amount');
    }

    /**
     * @param  list<string>  $natures
     */
    private function measure(string $tenantId, int $year, int $month, string $column, string $id, array $natures, string $field): string
    {
        $value = DB::table('analytical_ledger_entries')
            ->where('tenant_id', $tenantId)->whereNull('deleted_at')
            ->where('fiscal_year', $year)->where('fiscal_month', $month)
            ->where('source_type', '<>', 'overhead_allocation')
            ->where($column, $id)->whereIn('nature_type', $natures)
            ->sum($field);

        return Decimal::norm(number_format((float) $value, 4, '.', ''));
    }
}

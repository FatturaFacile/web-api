<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Projects\Commands\AllocateOverheadToProjectsCommand;
use Modules\Projects\Contracts\ProjectFxRateResolverInterface;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectCostEntry;
use Modules\Projects\Models\ProjectCostLedgerEntry;
use Modules\Projects\Models\ProjectTimesheet;
use Modules\Projects\Support\CostSourceType;
use Modules\Projects\Support\Money;

/**
 * Allocates an overhead pool of a cost centre (Mod 018: general costs, "ribaltamento costi generali") to projects by a
 * driver: approved labour hours of the period, direct cost of the period, or custom weights. Each project receives one
 * ledger line of source OverheadAllocation (the pool is in the base currency of the tenant); the shares are rounded to four decimals and the last project takes the
 * rounding difference, so the allocated amounts always add up to the pool. A run is identified by its key: running it
 * again replaces its lines, and projects that dropped out of the run lose theirs.
 */
final readonly class OverheadAllocationService
{
    public const array Drivers = ['labor_hours', 'direct_cost', 'custom_weights'];

    public function __construct(private ProjectFxRateResolverInterface $fx) {}

    /**
     * @return array{run_key: string, driver: string, pool_amount: string, allocated_amount: string, allocations: list<array{project_id: string, share_percent: string, amount: string}>}
     *
     * @throws ValidationException when no project carries any driver value
     */
    public function allocate(AllocateOverheadToProjectsCommand $command): array
    {
        return DB::transaction(function () use ($command): array {
            $projects = Project::query()
                ->when($command->projectIds !== [], fn ($query) => $query->whereIn('id', $command->projectIds), fn ($query) => $query->whereNotIn('status', [...Project::FinalStatuses, 'draft']))
                ->get();

            $weights = $this->weights($command, $projects->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all());
            $total = Money::add(...array_values($weights));

            if (! Money::isPositive($total)) {
                throw ValidationException::withMessages(['driver' => [__('projects_errors.evm.no_driver_value')]]);
            }

            $pool = Money::round($command->poolAmount, 4);
            $allocations = [];
            $remaining = $pool;
            $keys = array_keys(array_filter($weights, static fn (string $weight): bool => Money::isPositive($weight)));
            $lastKey = end($keys);

            foreach ($keys as $projectId) {
                $amount = $projectId === $lastKey ? Money::round($remaining, 4) : Money::round(Money::mul($pool, Money::div($weights[$projectId], $total) ?? '0'), 4);
                $remaining = Money::round(Money::sub($remaining, $amount), 4);
                $allocations[$projectId] = $amount;
            }

            $this->persist($command, $projects->keyBy('id')->all(), $allocations);

            return [
                'run_key' => $command->runKey,
                'driver' => $command->driver,
                'pool_amount' => $pool,
                'allocated_amount' => Money::round(Money::add(...array_values($allocations)), 4),
                'allocations' => array_map(static fn (string $projectId, string $amount): array => [
                    'project_id' => $projectId,
                    'share_percent' => Money::percent(Money::div($amount, $pool)) ?? '0.00',
                    'amount' => $amount,
                ], array_keys($allocations), array_values($allocations)),
            ];
        });
    }

    /**
     * @param  list<string>  $projectIds
     * @return array<string, string> driver value by project
     */
    private function weights(AllocateOverheadToProjectsCommand $command, array $projectIds): array
    {
        $weights = array_fill_keys($projectIds, '0');

        if ($command->driver === 'custom_weights') {
            foreach ($command->weights as $projectId => $weight) {
                if (array_key_exists($projectId, $weights)) {
                    $weights[$projectId] = Money::of($weight);
                }
            }

            return $weights;
        }

        if ($command->driver === 'labor_hours') {
            ProjectTimesheet::query()->whereIn('project_id', $projectIds)->where('status', 'approved')
                ->whereBetween('work_date', [$command->periodStart, $command->periodEnd])
                ->get(['project_id', 'hours_worked'])
                ->each(function (ProjectTimesheet $timesheet) use (&$weights): void {
                    $weights[(string) $timesheet->project_id] = Money::add($weights[(string) $timesheet->project_id], $timesheet->hours_worked);
                });

            return $weights;
        }

        ProjectCostEntry::query()->whereIn('project_id', $projectIds)->approved()
            ->whereBetween('incurred_date', [$command->periodStart, $command->periodEnd])
            ->get(['project_id', 'total_cost'])
            ->each(function (ProjectCostEntry $entry) use (&$weights): void {
                $weights[(string) $entry->project_id] = Money::add($weights[(string) $entry->project_id], $entry->total_cost);
            });

        return $weights;
    }

    /**
     * @param  array<string, Project>  $projects
     * @param  array<string, string>  $allocations
     */
    private function persist(AllocateOverheadToProjectsCommand $command, array $projects, array $allocations): void
    {
        $prefix = $command->runKey.':';

        ProjectCostLedgerEntry::query()->where('source_type', CostSourceType::OverheadAllocation->value)
            ->where('source_document_id', 'like', $prefix.'%')
            ->get()
            ->each(static function (ProjectCostLedgerEntry $stale) use ($allocations, $prefix): void {
                if (! array_key_exists(substr($stale->source_document_id, strlen($prefix)), $allocations)) {
                    $stale->delete();
                }
            });

        foreach ($allocations as $projectId => $amount) {
            $project = $projects[$projectId];
            $currency = strtoupper((string) ($project->currency ?? 'EUR'));
            $date = CarbonImmutable::parse($command->periodEnd)->toDateString();
            $rate = $this->fx->rateOn((string) $project->tenant_id, $currency, $date) ?? '1.000000';

            $entry = ProjectCostLedgerEntry::query()->withTrashed()->where('project_id', $projectId)
                ->where('source_type', CostSourceType::OverheadAllocation->value)->where('source_document_id', $prefix.$projectId)->first()
                ?? new ProjectCostLedgerEntry(['tenant_id' => $project->tenant_id, 'company_id' => $project->company_id, 'project_id' => $projectId, 'source_type' => CostSourceType::OverheadAllocation->value, 'source_document_id' => $prefix.$projectId]);

            $entry->fill([
                'wbs_node_id' => null,
                'cost_center_id' => $command->costCenterId,
                'source_document_reference' => $command->runKey,
                'committed_amount' => '0',
                'actual_amount' => Money::round(Money::div($amount, $rate) ?? $amount, 4),
                'currency_code' => $currency,
                'exchange_rate' => $rate,
                'base_currency_committed_amount' => '0',
                'base_currency_actual_amount' => $amount,
                'posting_date' => $date,
            ]);

            if ($entry->trashed()) {
                $entry->restore();
            }

            $entry->save();
        }
    }
}

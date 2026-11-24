<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Application\Handlers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\MrpPlanning\Application\Commands\AutoLevelFiniteCapacityScheduleCommand;
use Modules\MrpPlanning\Domain\Contracts\MrpSnapshotLoaderInterface;
use Modules\MrpPlanning\Domain\Enums\CapacitySchedulingMode;
use Modules\MrpPlanning\Domain\Enums\MrpRunStatus;
use Modules\MrpPlanning\Domain\Enums\ProposalStatus;
use Modules\MrpPlanning\Domain\Enums\ProposalType;
use Modules\MrpPlanning\Domain\Exceptions\MrpException;
use Modules\MrpPlanning\Domain\Exceptions\MrpNotFoundException;
use Modules\MrpPlanning\Domain\Services\CapacityScheduler;
use Modules\MrpPlanning\Domain\ValueObjects\RunParameters;
use Modules\MrpPlanning\Domain\ValueObjects\SchedulableOrder;
use Modules\MrpPlanning\Domain\ValueObjects\ScheduleBlock;
use Modules\MrpPlanning\Infrastructure\Models\MrpActionProposal;
use Modules\MrpPlanning\Infrastructure\Models\MrpRunExecution;
use Modules\MrpPlanning\Infrastructure\Models\MrpScheduleBlock;
use Modules\MrpPlanning\Infrastructure\Services\MrpPlanPersister;

/**
 * Re-levels the proposed production orders of a completed run against the finite capacity of their work centres,
 * alternative work centres included, in earliest-due-date order. Firm and released orders, and proposals the planner has
 * moved by hand, keep their blocks and load the buckets first. Buckets, overload flags and proposal dates are rebuilt.
 */
final class AutoLevelFiniteCapacityScheduleHandler
{
    public function __construct(
        private readonly MrpSnapshotLoaderInterface $loader,
        private readonly CapacityScheduler $scheduler,
        private readonly MrpPlanPersister $persister,
    ) {}

    /**
     * @return array{run: MrpRunExecution, overloaded_buckets_before: int, overloaded_buckets_after: int, leveled_proposals: int}
     */
    public function handle(AutoLevelFiniteCapacityScheduleCommand $command): array
    {
        $run = MrpRunExecution::query()->where('tenant_id', $command->tenantId)->whereKey($command->runId)->first();
        if (! $run instanceof MrpRunExecution) {
            throw new MrpNotFoundException('MRP run not found.');
        }
        if ($run->status !== MrpRunStatus::Completed) {
            throw new MrpException('Only a completed run can be leveled.');
        }

        $direction = $command->direction ?? $run->scheduling_direction;
        $today = CarbonImmutable::today();
        $snapshot = $this->loader->load($command->tenantId, new RunParameters(
            today: $today,
            planningHorizonDays: $run->planning_horizon_days,
            mode: $run->run_mode,
            capacityMode: CapacitySchedulingMode::FiniteCapacityConstraint,
            direction: $direction,
        ));

        $proposals = MrpActionProposal::query()->where('tenant_id', $command->tenantId)->where('mrp_run_id', $run->id)
            ->where('proposal_type', ProposalType::PlannedProductionOrder->value)->with('blocks')->get();

        $orders = [];
        $fixed = [];
        $levelIds = [];
        foreach ($proposals as $proposal) {
            $operations = $snapshot->items[(string) $proposal->mfg_item_id]->operations ?? [];
            $movable = $proposal->status === ProposalStatus::Proposed && $operations !== [] && ! $proposal->blocks->contains(static fn (MrpScheduleBlock $block): bool => $block->is_manual);
            if ($movable) {
                $orders[] = new SchedulableOrder((string) $proposal->id, (string) $proposal->suggested_order_qty, CarbonImmutable::parse($proposal->need_by_date), CarbonImmutable::parse($proposal->release_order_by_date), $operations);
                $levelIds[] = (string) $proposal->id;

                continue;
            }
            foreach ($proposal->blocks as $block) {
                $fixed[] = $this->toValueObject($block);
            }
        }

        $before = $this->persister->rebuild($run, $snapshot->calendars)['overloaded_buckets'];
        $placed = $this->scheduler->schedule($orders, $snapshot->calendars, $snapshot->alternatives, $today, true, $direction->value === 'BackwardFromDueDate', $fixed);
        $newBlocks = array_values(array_filter($placed, static fn (ScheduleBlock $block): bool => in_array($block->proposalId, $levelIds, true)));

        $after = DB::transaction(function () use ($run, $levelIds, $newBlocks, $snapshot): int {
            MrpScheduleBlock::query()->where('tenant_id', $run->tenant_id)->where('mrp_run_id', $run->id)->whereIn('proposal_id', $levelIds)->forceDelete();
            foreach ($newBlocks as $block) {
                $this->persister->saveBlock($run, $block);
            }

            return $this->persister->rebuild($run, $snapshot->calendars)['overloaded_buckets'];
        });

        return ['run' => $run->refresh(), 'overloaded_buckets_before' => $before, 'overloaded_buckets_after' => $after, 'leveled_proposals' => count($levelIds)];
    }

    private function toValueObject(MrpScheduleBlock $block): ScheduleBlock
    {
        return new ScheduleBlock((string) $block->id, (string) $block->proposal_id, (string) $block->work_center_id, (int) $block->operation_sequence, CarbonImmutable::parse($block->start_date), CarbonImmutable::parse($block->end_date), (int) $block->minutes, $block->segments ?? [], true, $block->original_work_center_id);
    }
}

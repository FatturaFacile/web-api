<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Infrastructure\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Manufacturing\Support\Money;
use Modules\MrpPlanning\Domain\Enums\ExceptionCode;
use Modules\MrpPlanning\Domain\Enums\ProposalStatus;
use Modules\MrpPlanning\Domain\Enums\ProposalType;
use Modules\MrpPlanning\Domain\Services\CapacityScheduler;
use Modules\MrpPlanning\Domain\ValueObjects\PlannedProposal;
use Modules\MrpPlanning\Domain\ValueObjects\ScheduleBlock;
use Modules\MrpPlanning\Domain\ValueObjects\WorkCenterCalendar;
use Modules\MrpPlanning\Infrastructure\Models\MrpActionProposal;
use Modules\MrpPlanning\Infrastructure\Models\MrpRunExecution;
use Modules\MrpPlanning\Infrastructure\Models\MrpScheduleBlock;
use Modules\MrpPlanning\Infrastructure\Models\MrpWorkCenterCapacityBucket;

/**
 * Writes engine results to the database and keeps the derived read models (capacity buckets, overload and late flags,
 * proposal dates) in step with the schedule blocks. Callers wrap it in a transaction.
 */
final class MrpPlanPersister
{
    /** The load percentage a bucket without any available minute reports when it is loaded. */
    private const string MaxLoadPercentage = '9999.99';

    public function __construct(private readonly CapacityScheduler $scheduler) {}

    public function saveProposal(MrpRunExecution $run, PlannedProposal $proposal, ?string $productId): MrpActionProposal
    {
        return MrpActionProposal::create([
            'id' => $proposal->id,
            'tenant_id' => $run->tenant_id,
            'mrp_run_id' => $run->id,
            'mfg_item_id' => $proposal->itemId,
            'product_id' => $productId,
            'bom_version_id' => $proposal->bomVersionId,
            'work_center_id' => $proposal->workCenterId,
            'low_level_code' => $proposal->lowLevelCode,
            'proposal_type' => $proposal->type,
            'status' => ProposalStatus::Proposed,
            'gross_requirement_qty' => $proposal->grossQty,
            'projected_on_hand_qty' => $proposal->projectedOnHandQty,
            'net_requirement_qty' => $proposal->netQty,
            'required_qty' => $proposal->requiredQty,
            'suggested_order_qty' => $proposal->suggestedQty,
            'need_by_date' => $proposal->needBy->toDateString(),
            'release_order_by_date' => $proposal->releaseBy->toDateString(),
            'planned_start_at' => $proposal->plannedStart->startOfDay(),
            'planned_end_at' => $proposal->plannedEnd->endOfDay(),
            'pegged_demand_source_ids' => $proposal->pegging,
            'exception_messages' => $proposal->exceptions,
            'supply_reference' => $proposal->supplyReference,
        ]);
    }

    public function saveBlock(MrpRunExecution $run, ScheduleBlock $block): MrpScheduleBlock
    {
        return MrpScheduleBlock::create([
            'id' => $block->id,
            'tenant_id' => $run->tenant_id,
            'mrp_run_id' => $run->id,
            'proposal_id' => $block->proposalId,
            'work_center_id' => $block->workCenterId,
            'original_work_center_id' => $block->originalWorkCenterId,
            'operation_sequence' => $block->sequence,
            'start_date' => $block->startDate->toDateString(),
            'end_date' => $block->endDate->toDateString(),
            'minutes' => $block->minutes,
            'segments' => $block->segments,
            'is_manual' => $block->isManual,
        ]);
    }

    /**
     * Moves proposals (and their blocks) of earlier runs into the run: firm orders and the kept proposals of a net-change run.
     *
     * @param  list<string>  $proposalIds
     */
    public function relink(MrpRunExecution $run, array $proposalIds): void
    {
        if ($proposalIds === []) {
            return;
        }
        MrpActionProposal::query()->where('tenant_id', $run->tenant_id)->whereIn('id', $proposalIds)->update(['mrp_run_id' => $run->id]);
        MrpScheduleBlock::query()->where('tenant_id', $run->tenant_id)->whereIn('proposal_id', $proposalIds)->update(['mrp_run_id' => $run->id]);
    }

    /**
     * Every schedule block of the run as a value object.
     *
     * @return list<ScheduleBlock>
     */
    public function blocks(MrpRunExecution $run): array
    {
        return MrpScheduleBlock::query()->where('tenant_id', $run->tenant_id)->where('mrp_run_id', $run->id)->get()
            ->map(static fn (MrpScheduleBlock $block): ScheduleBlock => new ScheduleBlock(
                (string) $block->id, (string) $block->proposal_id, (string) $block->work_center_id, (int) $block->operation_sequence,
                CarbonImmutable::parse($block->start_date), CarbonImmutable::parse($block->end_date), (int) $block->minutes, $block->segments ?? [], (bool) $block->is_manual, $block->original_work_center_id,
            ))->values()->all();
    }

    /**
     * Rebuilds the capacity buckets of the run from its blocks, then refreshes the dates and the overload / late flags of
     * the scheduled proposals.
     *
     * @param  array<string, WorkCenterCalendar>  $calendars
     * @return array{overloaded_buckets: int, max_load_percentage: string, buckets: int}
     */
    public function rebuild(MrpRunExecution $run, array $calendars): array
    {
        $blocks = $this->blocks($run);
        $buckets = $this->scheduler->buckets($blocks, $calendars);

        $assigned = [];
        foreach ($blocks as $block) {
            foreach ($block->segments as $segment) {
                $assigned[$block->workCenterId.'|'.$segment['date']][$block->proposalId] = $block->proposalId;
            }
        }

        MrpWorkCenterCapacityBucket::query()->where('tenant_id', $run->tenant_id)->where('mrp_run_id', $run->id)->forceDelete();

        $overloaded = [];
        $max = '0';
        foreach ($buckets as $bucket) {
            $key = $bucket['work_center_id'].'|'.$bucket['bucket_date'];
            $percentage = self::loadPercentage($bucket['loaded_minutes'], $bucket['available_minutes']);
            $isOverloaded = $bucket['loaded_minutes'] > $bucket['available_minutes'];
            if ($isOverloaded) {
                $overloaded[$key] = true;
            }
            $max = bccomp($percentage, $max, 2) > 0 ? $percentage : $max;

            MrpWorkCenterCapacityBucket::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $run->tenant_id,
                'mrp_run_id' => $run->id,
                'work_center_id' => $bucket['work_center_id'],
                'bucket_date' => $bucket['bucket_date'],
                'available_minutes' => $bucket['available_minutes'],
                'loaded_minutes' => $bucket['loaded_minutes'],
                'load_percentage' => $percentage,
                'is_overloaded' => $isOverloaded,
                'assigned_proposal_ids' => array_values($assigned[$key] ?? []),
            ]);
        }

        $this->refreshProposals($run, $overloaded);

        return ['overloaded_buckets' => count($overloaded), 'max_load_percentage' => $max, 'buckets' => count($buckets)];
    }

    /**
     * Loaded minutes over available minutes, in percent; a loaded bucket without capacity is capped at the maximum.
     */
    public static function loadPercentage(int $loaded, int $available): string
    {
        if ($available <= 0) {
            return $loaded > 0 ? self::MaxLoadPercentage : '0.00';
        }
        $value = Money::round(Money::div(Money::mul((string) $loaded, '100'), (string) $available), 2);

        return bccomp($value, self::MaxLoadPercentage, 2) > 0 ? self::MaxLoadPercentage : $value;
    }

    /**
     * @param  array<string, true>  $overloaded  "workCenterId|date" of the overloaded buckets
     */
    private function refreshProposals(MrpRunExecution $run, array $overloaded): void
    {
        $proposals = MrpActionProposal::query()->where('tenant_id', $run->tenant_id)->where('mrp_run_id', $run->id)
            ->where('proposal_type', ProposalType::PlannedProductionOrder->value)->with('blocks')->get();

        foreach ($proposals as $proposal) {
            $blocks = $proposal->blocks;
            if ($blocks->isEmpty()) {
                continue;
            }

            $flags = collect($proposal->exception_messages ?? [])->reject(static fn (string $code): bool => in_array($code, [ExceptionCode::CapacityOverload->value, ExceptionCode::LateDelivery->value], true))->values();
            $isOverloaded = $blocks->contains(static fn (MrpScheduleBlock $block): bool => collect($block->segments ?? [])->contains(static fn (array $segment): bool => isset($overloaded[$block->work_center_id.'|'.$segment['date']])));
            $start = $blocks->map(static fn (MrpScheduleBlock $block): CarbonImmutable => CarbonImmutable::parse($block->start_date))->min();
            $end = $blocks->map(static fn (MrpScheduleBlock $block): CarbonImmutable => CarbonImmutable::parse($block->end_date))->max();

            if ($isOverloaded) {
                $flags->push(ExceptionCode::CapacityOverload->value);
            }
            if ($end > CarbonImmutable::parse($proposal->need_by_date)->startOfDay()) {
                $flags->push(ExceptionCode::LateDelivery->value);
            }

            $first = $blocks->sortBy('operation_sequence')->first();
            $proposal->forceFill([
                'exception_messages' => $flags->all(),
                'planned_start_at' => $start->startOfDay(),
                'planned_end_at' => $end->endOfDay(),
                'work_center_id' => $first?->work_center_id,
            ])->save();
        }
    }
}

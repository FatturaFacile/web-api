<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Application\Handlers;

use Carbon\CarbonImmutable;
use Modules\Manufacturing\Models\MfgWorkCenter;
use Modules\MrpPlanning\Application\Queries\GetGanttCapacityScheduleQuery;
use Modules\MrpPlanning\Domain\Exceptions\MrpException;
use Modules\MrpPlanning\Domain\Exceptions\MrpNotFoundException;
use Modules\MrpPlanning\Infrastructure\Models\MrpActionProposal;
use Modules\MrpPlanning\Infrastructure\Models\MrpRunExecution;
use Modules\MrpPlanning\Infrastructure\Models\MrpScheduleBlock;
use Modules\MrpPlanning\Infrastructure\Models\MrpWorkCenterAlternative;
use Modules\MrpPlanning\Infrastructure\Models\MrpWorkCenterCapacityBucket;

/**
 * Everything the Gantt and the load histogram draw for a window: the work centres that carry load (or were asked for),
 * their daily buckets with load percentage and overload flag, the scheduled blocks and the alternative work centres.
 */
final class GetGanttCapacityScheduleHandler
{
    private const int MaxWindowDays = 366;

    /**
     * @return array{run_id: string, from: string, to: string, work_centers: list<array<string, mixed>>}
     */
    public function handle(GetGanttCapacityScheduleQuery $query): array
    {
        if ($query->to->lt($query->from) || $query->from->diffInDays($query->to) > self::MaxWindowDays) {
            throw new MrpException('The window must end after it starts and span at most '.self::MaxWindowDays.' days.');
        }
        $run = MrpRunExecution::query()->where('tenant_id', $query->tenantId)->whereKey($query->runId)->first();
        if (! $run instanceof MrpRunExecution) {
            throw new MrpNotFoundException('MRP run not found.');
        }

        $from = $query->from->toDateString();
        $to = $query->to->toDateString();

        $blocks = MrpScheduleBlock::query()->where('tenant_id', $query->tenantId)->where('mrp_run_id', $run->id)
            ->whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $from)
            ->when($query->workCenterIds !== [], static fn ($builder) => $builder->whereIn('work_center_id', $query->workCenterIds))
            ->orderBy('start_date')->get();
        $buckets = MrpWorkCenterCapacityBucket::query()->where('tenant_id', $query->tenantId)->where('mrp_run_id', $run->id)
            ->whereDate('bucket_date', '>=', $from)->whereDate('bucket_date', '<=', $to)
            ->when($query->workCenterIds !== [], static fn ($builder) => $builder->whereIn('work_center_id', $query->workCenterIds))
            ->orderBy('bucket_date')->get();

        $proposals = MrpActionProposal::query()->with('item')->where('tenant_id', $query->tenantId)->whereIn('id', $blocks->pluck('proposal_id')->unique()->all())->get()->keyBy('id');
        $centerIds = collect($query->workCenterIds)->merge($blocks->pluck('work_center_id'))->merge($buckets->pluck('work_center_id'))->unique()->values();
        $names = MfgWorkCenter::query()->whereIn('id', $centerIds->all())->get()->keyBy('id');
        $alternatives = MrpWorkCenterAlternative::query()->where('tenant_id', $query->tenantId)->whereIn('work_center_id', $centerIds->all())->orderBy('priority')->get()->groupBy('work_center_id');

        $workCenters = [];
        foreach ($centerIds as $centerId) {
            $center = $names->get($centerId);
            $workCenters[] = [
                'id' => (string) $centerId,
                'code' => $center?->work_center_code,
                'name' => $center?->name,
                'alternative_work_center_ids' => ($alternatives->get($centerId) ?? collect())->pluck('alternative_work_center_id')->map(static fn (mixed $id): string => (string) $id)->all(),
                'buckets' => $buckets->where('work_center_id', $centerId)->map(static fn (MrpWorkCenterCapacityBucket $bucket): array => [
                    'bucket_date' => $bucket->bucket_date->toDateString(),
                    'shift_code' => $bucket->shift_code,
                    'available_minutes' => $bucket->available_minutes,
                    'loaded_minutes' => $bucket->loaded_minutes,
                    'load_percentage' => (string) $bucket->load_percentage,
                    'is_overloaded' => $bucket->is_overloaded,
                    'assigned_proposal_ids' => $bucket->assigned_proposal_ids ?? [],
                ])->values()->all(),
                'blocks' => $blocks->where('work_center_id', $centerId)->map(static function (MrpScheduleBlock $block) use ($proposals): array {
                    $proposal = $proposals->get($block->proposal_id);

                    return [
                        'id' => (string) $block->id,
                        'proposal_id' => (string) $block->proposal_id,
                        'item_code' => $proposal?->item?->item_code,
                        'item_name' => $proposal?->item?->name,
                        'proposal_status' => $proposal?->status->value,
                        'exception_messages' => (array) $proposal?->exception_messages,
                        'operation_sequence' => $block->operation_sequence,
                        'start_date' => CarbonImmutable::parse($block->start_date)->toDateString(),
                        'end_date' => CarbonImmutable::parse($block->end_date)->toDateString(),
                        'minutes' => $block->minutes,
                        'segments' => $block->segments ?? [],
                        'is_manual' => $block->is_manual,
                        'original_work_center_id' => $block->original_work_center_id,
                    ];
                })->values()->all(),
            ];
        }

        return ['run_id' => (string) $run->id, 'from' => $from, 'to' => $to, 'work_centers' => $workCenters];
    }
}

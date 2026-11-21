<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Application\Handlers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\MrpPlanning\Application\Commands\RescheduleGanttBlockCommand;
use Modules\MrpPlanning\Domain\Contracts\MrpSnapshotLoaderInterface;
use Modules\MrpPlanning\Domain\Enums\ProposalStatus;
use Modules\MrpPlanning\Domain\Exceptions\MrpException;
use Modules\MrpPlanning\Domain\Exceptions\MrpNotFoundException;
use Modules\MrpPlanning\Domain\Services\CapacityScheduler;
use Modules\MrpPlanning\Domain\ValueObjects\WorkCenterCalendar;
use Modules\MrpPlanning\Infrastructure\Models\MrpActionProposal;
use Modules\MrpPlanning\Infrastructure\Models\MrpRunExecution;
use Modules\MrpPlanning\Infrastructure\Models\MrpScheduleBlock;
use Modules\MrpPlanning\Infrastructure\Models\MrpWorkCenterAlternative;
use Modules\MrpPlanning\Infrastructure\Services\MrpPlanPersister;

/**
 * A planner drags an operation on the Gantt: it keeps its minutes but starts on the chosen day (or ends on it) on the
 * same or on an alternative work centre of the routing's work centre. The manual placement may overload a bucket, which
 * is then reported; the operation is marked manual so that auto-leveling leaves it alone.
 */
final class RescheduleGanttBlockHandler
{
    public function __construct(
        private readonly MrpSnapshotLoaderInterface $loader,
        private readonly CapacityScheduler $scheduler,
        private readonly MrpPlanPersister $persister,
    ) {}

    /**
     * @return array{block: MrpScheduleBlock, proposal: MrpActionProposal, buckets: array{overloaded_buckets: int, max_load_percentage: string, buckets: int}}
     */
    public function handle(RescheduleGanttBlockCommand $command): array
    {
        if ($command->startDate === null && $command->endDate === null && $command->workCenterId === null) {
            throw new MrpException('Give a new start date, end date or work centre.');
        }

        $block = MrpScheduleBlock::query()->where('tenant_id', $command->tenantId)->whereKey($command->blockId)->first();
        if (! $block instanceof MrpScheduleBlock) {
            throw new MrpNotFoundException('Schedule block not found.');
        }
        $proposal = MrpActionProposal::query()->where('tenant_id', $command->tenantId)->whereKey($block->proposal_id)->firstOrFail();
        if (! in_array($proposal->status, [ProposalStatus::Proposed, ProposalStatus::Firm], true)) {
            throw new MrpException('Only blocks of proposed or firm proposals can be rescheduled.');
        }
        $run = MrpRunExecution::query()->where('tenant_id', $command->tenantId)->whereKey($block->mrp_run_id)->firstOrFail();

        $origin = $block->original_work_center_id ?? $block->work_center_id;
        $target = $command->workCenterId ?? $block->work_center_id;
        if ($target !== $origin) {
            $allowed = MrpWorkCenterAlternative::query()->where('tenant_id', $command->tenantId)->where('work_center_id', $origin)->where('alternative_work_center_id', $target)->exists();
            if (! $allowed) {
                throw new MrpException('The work centre is not an alternative of the routing work centre.');
            }
        }

        $backward = $command->startDate === null && $command->endDate !== null;
        $anchor = ($backward ? $command->endDate : $command->startDate) ?? CarbonImmutable::parse($block->start_date);
        $calendars = $this->loader->calendars([$origin, $target], CarbonImmutable::today(), $anchor->addDays(800));
        $others = array_values(array_filter($this->persister->blocks($run), static fn ($other): bool => $other->id !== (string) $block->id));
        $segments = $this->scheduler->placeSingle($target, (int) $block->minutes, $anchor, $backward, false, $calendars, $others);

        $buckets = DB::transaction(function () use ($block, $target, $origin, $segments, $calendars, $run): array {
            $block->forceFill([
                'work_center_id' => $target,
                'original_work_center_id' => $target !== $origin ? $origin : null,
                'start_date' => $segments[0]['date'],
                'end_date' => $segments[array_key_last($segments)]['date'],
                'segments' => $segments,
                'is_manual' => true,
            ])->save();

            return $this->persister->rebuild($run, $this->calendarsOfRun($run, $calendars));
        });

        return ['block' => $block->refresh(), 'proposal' => $proposal->refresh(), 'buckets' => $buckets];
    }

    /**
     * The calendars of every work centre the run loads, the ones already loaded included.
     *
     * @param  array<string, WorkCenterCalendar>  $known
     * @return array<string, WorkCenterCalendar>
     */
    private function calendarsOfRun(MrpRunExecution $run, array $known): array
    {
        $ids = MrpScheduleBlock::query()->where('tenant_id', $run->tenant_id)->where('mrp_run_id', $run->id)->distinct()->pluck('work_center_id')->map(static fn (mixed $id): string => (string) $id)->all();
        $missing = array_values(array_diff($ids, array_keys($known)));

        return $missing === [] ? $known : [...$known, ...$this->loader->calendars($missing, CarbonImmutable::today(), CarbonImmutable::today()->addDays(800))];
    }
}

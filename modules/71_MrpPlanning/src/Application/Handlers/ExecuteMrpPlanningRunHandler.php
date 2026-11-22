<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Application\Handlers;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\MfgItem;
use Modules\MrpPlanning\Application\Commands\ExecuteMrpPlanningRunCommand;
use Modules\MrpPlanning\Domain\Contracts\MrpSnapshotLoaderInterface;
use Modules\MrpPlanning\Domain\Enums\MrpRunMode;
use Modules\MrpPlanning\Domain\Enums\MrpRunStatus;
use Modules\MrpPlanning\Domain\Enums\ProposalStatus;
use Modules\MrpPlanning\Domain\Events\MrpPlanningEvent;
use Modules\MrpPlanning\Domain\Exceptions\MrpException;
use Modules\MrpPlanning\Domain\Services\MrpCalculationService;
use Modules\MrpPlanning\Domain\ValueObjects\MrpResult;
use Modules\MrpPlanning\Domain\ValueObjects\MrpSnapshot;
use Modules\MrpPlanning\Domain\ValueObjects\RunParameters;
use Modules\MrpPlanning\Domain\ValueObjects\ScheduleBlock;
use Modules\MrpPlanning\Infrastructure\Models\MrpActionProposal;
use Modules\MrpPlanning\Infrastructure\Models\MrpRunExecution;
use Modules\MrpPlanning\Infrastructure\Models\MrpScheduleBlock;
use Modules\MrpPlanning\Infrastructure\Services\MrpPlanPersister;
use Throwable;

/**
 * Executes an MRP run: loads the snapshot of the upstream modules, runs the engine, stores proposals, pegging, schedule
 * blocks and capacity buckets in one transaction and records KPIs and the duration. A domain failure (a cyclic BOM) ends
 * the run as Failed with its message; anything else is rethrown after the run is marked Failed.
 *
 * Firm proposals of earlier runs are commitments: they move into the new run and count as scheduled receipts. A
 * net-change run additionally keeps the proposals of the items whose inputs (and those of their parents) did not change.
 */
final class ExecuteMrpPlanningRunHandler
{
    public function __construct(
        private readonly MrpSnapshotLoaderInterface $loader,
        private readonly MrpCalculationService $engine,
        private readonly MrpPlanPersister $persister,
        private readonly EventDispatcher $dispatcher,
    ) {}

    public function handle(ExecuteMrpPlanningRunCommand $command): MrpRunExecution
    {
        $startedAt = hrtime(true);
        $today = ($command->today ?? CarbonImmutable::today())->startOfDay();

        $run = MrpRunExecution::create([
            'tenant_id' => $command->tenantId,
            'run_code' => 'MRP-'.now()->format('Ymd-His').'-'.strtoupper(Str::random(4)),
            'status' => MrpRunStatus::Running,
            'run_mode' => $command->runMode,
            'planning_horizon_days' => $command->planningHorizonDays,
            'capacity_scheduling_mode' => $command->capacitySchedulingMode,
            'scheduling_direction' => $command->schedulingDirection,
            'include_cross_border_customs_lead_time' => $command->includeCrossBorderCustomsLeadTime,
            'apply_supplier_shutdown_calendars' => $command->applySupplierShutdownCalendars,
            'started_at' => now(),
            'created_by' => $command->userId !== null && Str::isUuid($command->userId) ? $command->userId : null,
        ]);

        try {
            $parameters = new RunParameters(
                today: $today,
                planningHorizonDays: $command->planningHorizonDays,
                mode: $command->runMode,
                capacityMode: $command->capacitySchedulingMode,
                direction: $command->schedulingDirection,
                includeCrossBorderCustomsLeadTime: $command->includeCrossBorderCustomsLeadTime,
                applySupplierShutdownCalendars: $command->applySupplierShutdownCalendars,
                tenantCountry: (string) (Tenant::query()->whereKey($command->tenantId)->value('country') ?? 'CH'),
                defaultCustomsDays: (int) config('mrp_planning.default_customs_days', 2),
            );

            $snapshot = $this->loader->load($command->tenantId, $parameters);
            $signatures = $this->signatures($snapshot);
            $kept = $command->runMode === MrpRunMode::NetChange ? $this->keptFromPreviousRun($run, $snapshot, $signatures) : ['items' => [], 'proposal_ids' => [], 'blocks' => []];
            if ($kept['blocks'] !== []) {
                $snapshot = $snapshot->withFixedBlocks([...$snapshot->fixedBlocks, ...$kept['blocks']]);
            }

            $result = $this->engine->calculate($snapshot);
            $metrics = DB::transaction(fn (): array => $this->persist($run, $snapshot, $result, $kept, $signatures));
        } catch (MrpException $exception) {
            return $this->fail($run, $exception->getMessage(), $startedAt);
        } catch (Throwable $exception) {
            $this->fail($run, $exception->getMessage(), $startedAt);

            throw $exception;
        }

        $run->forceFill([
            'status' => MrpRunStatus::Completed,
            'completed_at' => now(),
            'execution_duration_ms' => $this->elapsedMs($startedAt),
            'summary_metrics' => $metrics,
        ])->save();

        $this->dispatcher->publishAndDispatch(new MrpPlanningEvent(
            MrpPlanningEvent::RunCompleted, 'mrp_run', (string) $run->id,
            ['mrp_run_id' => (string) $run->id, 'run_code' => $run->run_code, 'tenant_id' => $command->tenantId, 'metrics' => $metrics],
            $command->tenantId,
        ));

        return $run->refresh();
    }

    /**
     * @param  array{items: array<string, true>, proposal_ids: list<string>, blocks: list<ScheduleBlock>}  $kept
     * @param  array<string, string>  $signatures
     * @return array<string, mixed>
     */
    private function persist(MrpRunExecution $run, MrpSnapshot $snapshot, MrpResult $result, array $kept, array $signatures): array
    {
        $productIds = MfgItem::query()->where('tenant_id', $run->tenant_id)->whereIn('id', array_keys($snapshot->items))->pluck('product_id', 'id');

        $saved = [];
        foreach ($result->proposals as $proposal) {
            if (isset($kept['items'][$proposal->itemId])) {
                continue;
            }
            $this->persister->saveProposal($run, $proposal, $productIds[$proposal->itemId] ?? null);
            $saved[$proposal->id] = $proposal;
        }
        foreach ($result->blocks as $block) {
            if (isset($saved[$block->proposalId])) {
                $this->persister->saveBlock($run, $block);
            }
        }

        $firmIds = MrpActionProposal::query()->where('tenant_id', $run->tenant_id)->where('status', ProposalStatus::Firm->value)->where('mrp_run_id', '!=', $run->id)->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();
        $this->persister->relink($run, [...$firmIds, ...$kept['proposal_ids']]);
        $capacity = $this->persister->rebuild($run, $snapshot->calendars);

        $counts = [];
        $exceptions = [];
        foreach (MrpActionProposal::query()->where('tenant_id', $run->tenant_id)->where('mrp_run_id', $run->id)->get(['proposal_type', 'exception_messages']) as $row) {
            $counts[$row->proposal_type->value] = ($counts[$row->proposal_type->value] ?? 0) + 1;
            foreach ($row->exception_messages ?? [] as $code) {
                $exceptions[$code] = ($exceptions[$code] ?? 0) + 1;
            }
        }

        return [
            'items_planned' => count($snapshot->items),
            'max_low_level_code' => $result->lowLevelCodes === [] ? 0 : max($result->lowLevelCodes),
            'proposals_total' => array_sum($counts),
            'proposals_by_type' => $counts,
            'exceptions_by_code' => $exceptions,
            'overloaded_buckets' => $capacity['overloaded_buckets'],
            'max_load_percentage' => $capacity['max_load_percentage'],
            'kept_proposals' => count($kept['proposal_ids']),
            'firm_carried_over' => count($firmIds),
            'item_signatures' => $signatures,
        ];
    }

    /**
     * A fingerprint of everything that drives the plan of an item: its master data, bill of materials, independent demand
     * and scheduled receipts.
     *
     * @return array<string, string>
     */
    private function signatures(MrpSnapshot $snapshot): array
    {
        $signatures = [];
        foreach ($snapshot->items as $id => $item) {
            $demands = array_values(array_filter($snapshot->demands, static fn ($line): bool => $line->itemId === $id));
            $supplies = array_values(array_filter($snapshot->supplies, static fn ($line): bool => $line->itemId === $id));
            $signatures[$id] = sha1((string) json_encode([$item, $demands, $supplies]));
        }

        return $signatures;
    }

    /**
     * Net change: the proposals of the previous completed run stay for the items whose fingerprint is unchanged and that
     * are not a component of a changed item.
     *
     * @param  array<string, string>  $signatures
     * @return array{items: array<string, true>, proposal_ids: list<string>, blocks: list<ScheduleBlock>}
     */
    private function keptFromPreviousRun(MrpRunExecution $run, MrpSnapshot $snapshot, array $signatures): array
    {
        $none = ['items' => [], 'proposal_ids' => [], 'blocks' => []];
        $previous = MrpRunExecution::query()->where('tenant_id', $run->tenant_id)->where('status', MrpRunStatus::Completed->value)->where('id', '!=', $run->id)->orderByDesc('completed_at')->first();
        /** @var array<string, string> $before */
        $before = (array) ($previous?->summary_metrics['item_signatures'] ?? []);
        if ($previous === null || $before === []) {
            return $none;
        }

        $changed = [];
        foreach ($signatures as $id => $signature) {
            if (($before[$id] ?? null) !== $signature) {
                $changed[$id] = true;
            }
        }
        $queue = array_keys($changed);
        while ($queue !== []) {
            $id = (string) array_shift($queue);
            foreach ($snapshot->items[$id]->components as $line) {
                if (isset($snapshot->items[$line->childItemId]) && ! isset($changed[$line->childItemId])) {
                    $changed[$line->childItemId] = true;
                    $queue[] = $line->childItemId;
                }
            }
        }

        $unchanged = array_diff_key($signatures, $changed);
        if ($unchanged === []) {
            return $none;
        }

        $proposalIds = MrpActionProposal::query()->where('tenant_id', $run->tenant_id)->where('mrp_run_id', $previous->id)->where('status', ProposalStatus::Proposed->value)
            ->whereIn('mfg_item_id', array_keys($unchanged))->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();
        $blocks = MrpScheduleBlock::query()->where('tenant_id', $run->tenant_id)->whereIn('proposal_id', $proposalIds)->get()
            ->map(static fn (MrpScheduleBlock $block): ScheduleBlock => new ScheduleBlock((string) $block->id, (string) $block->proposal_id, (string) $block->work_center_id, (int) $block->operation_sequence, CarbonImmutable::parse($block->start_date), CarbonImmutable::parse($block->end_date), (int) $block->minutes, $block->segments ?? [], true, $block->original_work_center_id))
            ->values()->all();

        return ['items' => array_map(static fn (): bool => true, $unchanged), 'proposal_ids' => $proposalIds, 'blocks' => $blocks];
    }

    private function fail(MrpRunExecution $run, string $message, int $startedAt): MrpRunExecution
    {
        $run->forceFill([
            'status' => MrpRunStatus::Failed,
            'completed_at' => now(),
            'execution_duration_ms' => $this->elapsedMs($startedAt),
            'error_message' => Str::limit($message, 1000),
        ])->save();

        return $run;
    }

    private function elapsedMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}

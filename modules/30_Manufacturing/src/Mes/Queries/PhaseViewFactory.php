<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Mes\Queries;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Manufacturing\Enums\PhaseAction;
use Modules\Manufacturing\Enums\PhaseStatus;
use Modules\Manufacturing\Mes\Time\PhaseTimeCalculator;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgMesMaterialConsumption;
use Modules\Manufacturing\Models\MfgMesPhase;
use Modules\Manufacturing\Models\MfgMesProductionDeclaration;
use Modules\Manufacturing\Models\MfgMesTimeLog;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgProductionOrderOperation;
use Modules\Manufacturing\Support\Money;

/**
 * Read model of the operator board: one flat, UI-ready row per phase, or per operation nobody has taken over yet. Times
 * are computed from the server timestamps as of `server_time`; clients add the seconds elapsed since they received it to
 * the open interval, so a reload or a second tablet shows the same numbers.
 */
final class PhaseViewFactory
{
    public function __construct(private readonly PhaseTimeCalculator $calculator) {}

    /**
     * Rows of phases.
     *
     * @param  Collection<int, MfgMesPhase>  $phases
     * @return list<array<string, mixed>>
     */
    public function forPhases(Collection $phases, CarbonImmutable $now): array
    {
        if ($phases->isEmpty()) {
            return [];
        }

        $phaseIds = $phases->pluck('id')->all();
        $operations = MfgProductionOrderOperation::query()->whereIn('id', $phases->pluck('production_order_operation_id'))->get()->keyBy('id');
        $context = $this->orderContext($operations->pluck('production_order_id')->unique()->all());
        $logs = MfgMesTimeLog::query()->whereIn('phase_id', $phaseIds)->orderBy('started_at')->get()->groupBy('phase_id');
        $declared = MfgMesProductionDeclaration::query()->whereIn('phase_id', $phaseIds)->get()->groupBy('phase_id');
        $consumed = MfgMesMaterialConsumption::query()->whereIn('phase_id', $phaseIds)->get()->groupBy('phase_id');

        $rows = [];
        foreach ($phases as $phase) {
            /** @var MfgProductionOrderOperation $operation */
            $operation = $operations[$phase->production_order_operation_id];
            $phaseLogs = $logs->get($phase->id, collect());
            $open = $phaseLogs->first(static fn (MfgMesTimeLog $log): bool => $log->ended_at === null);
            $good = '0';
            $scrap = '0';
            foreach ($declared->get($phase->id, collect()) as $declaration) {
                $good = Money::add($good, $declaration->good_quantity);
                $scrap = Money::add($scrap, $declaration->scrap_quantity);
            }

            $rows[] = [
                'id' => (string) $phase->id,
                'version' => $phase->version,
                'status' => $phase->status->value,
                'allowed_actions' => array_map(static fn (PhaseAction $action): string => $action->value, $phase->status->allowedActions()),
                'resume_to' => $phase->resume_to?->value,
                'machine_mode' => $phase->machine_mode->value,
                'operator_id' => $phase->operator_id,
                'machine_id' => $phase->machine_id,
                'work_center_id' => (string) $phase->work_center_id,
                ...$this->operationBlock($operation, $context),
                'open_interval' => $open instanceof MfgMesTimeLog ? [
                    'category' => $open->category->value,
                    'started_at' => $open->started_at->toIso8601String(),
                    'machine_attended' => $open->machine_attended,
                    'suspension_reason' => $open->suspension_reason?->value,
                ] : null,
                'times' => $this->calculator->summarize($phaseLogs, $now)->toArray(),
                'declared' => ['good_quantity' => Money::qty($good), 'scrap_quantity' => Money::qty($scrap)],
                'consumptions_count' => $consumed->get($phase->id, collect())->count(),
                'taken_over_at' => $phase->taken_over_at->toIso8601String(),
                'closed_at' => $phase->closed_at?->toIso8601String(),
                'server_time' => $now->toIso8601String(),
            ];
        }

        return $rows;
    }

    /**
     * Rows of operations nobody has taken over: status `available`, only the take-over offered.
     *
     * @param  Collection<int, MfgProductionOrderOperation>  $operations
     * @return list<array<string, mixed>>
     */
    public function forOperations(Collection $operations, CarbonImmutable $now): array
    {
        if ($operations->isEmpty()) {
            return [];
        }

        $context = $this->orderContext($operations->pluck('production_order_id')->unique()->all());
        $empty = $this->calculator->summarize([], $now)->toArray();
        $rows = [];
        foreach ($operations as $operation) {
            $rows[] = [
                'id' => null,
                'version' => 0,
                'status' => PhaseStatus::Available->value,
                'allowed_actions' => array_map(static fn (PhaseAction $action): string => $action->value, PhaseStatus::Available->allowedActions()),
                'resume_to' => null,
                'machine_mode' => 'attended',
                'operator_id' => null,
                'machine_id' => $operation->machine_center_id,
                'work_center_id' => (string) $operation->work_center_id,
                ...$this->operationBlock($operation, $context),
                'open_interval' => null,
                'times' => $empty,
                'declared' => ['good_quantity' => '0.0000', 'scrap_quantity' => '0.0000'],
                'consumptions_count' => 0,
                'taken_over_at' => null,
                'closed_at' => null,
                'server_time' => $now->toIso8601String(),
            ];
        }

        return $rows;
    }

    /**
     * Orders and items of the given orders, keyed by order id.
     *
     * @param  list<mixed>  $orderIds
     * @return array<string, array{order: MfgProductionOrder, item: MfgItem|null}>
     */
    private function orderContext(array $orderIds): array
    {
        $orders = MfgProductionOrder::query()->whereIn('id', $orderIds)->get();
        $items = MfgItem::query()->whereIn('id', $orders->pluck('mfg_item_id'))->get()->keyBy('id');
        $context = [];
        foreach ($orders as $order) {
            $context[(string) $order->id] = ['order' => $order, 'item' => $items->get($order->mfg_item_id)];
        }

        return $context;
    }

    /**
     * The order and operation block shared by both kinds of rows.
     *
     * @param  array<string, array{order: MfgProductionOrder, item: MfgItem|null}>  $context
     * @return array<string, mixed>
     */
    private function operationBlock(MfgProductionOrderOperation $operation, array $context): array
    {
        $entry = $context[(string) $operation->production_order_id] ?? null;

        return [
            'production_order' => [
                'id' => (string) $operation->production_order_id,
                'order_number' => $entry['order']->order_number ?? null,
                'item_code' => $entry['item']->item_code ?? null,
                'item_name' => $entry['item']->name ?? null,
                'target_quantity' => isset($entry['order']) ? Money::qty($entry['order']->target_quantity) : null,
            ],
            'operation' => [
                'id' => (string) $operation->id,
                'sequence' => $operation->sequence,
                'name' => $operation->name,
                'completed_quantity' => Money::qty($operation->completed_quantity),
                'scrapped_quantity' => Money::qty($operation->scrapped_quantity),
            ],
        ];
    }
}

<?php

declare(strict_types=1);

namespace Modules\Planning\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Planning\Contracts\DemandForecastSource;
use Modules\Planning\Models\PlanningPlanLine;
use Modules\Planning\Models\PlanningScenario;

/**
 * Demand and supply lines of a draft scenario. A line is identified by its side, item and month: saving a second line
 * for the same combination replaces the quantity instead of duplicating the line.
 */
final readonly class PlanLineService
{
    public function __construct(private ScenarioService $scenarios, private DemandForecastSource $forecasts) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(PlanningScenario $scenario, array $data): PlanningPlanLine
    {
        return DB::transaction(function () use ($scenario, $data): PlanningPlanLine {
            $this->scenarios->assertDraft($scenario);
            $data = $this->resolveItem($scenario, $data);
            $period = $this->period($scenario, (string) $data['period']);

            $line = $this->find($scenario, (string) $data['line_type'], (string) $data['item_code'], $period);

            if ($line === null) {
                return PlanningPlanLine::create([
                    'tenant_id' => $scenario->tenant_id,
                    'scenario_id' => $scenario->id,
                    'line_type' => $data['line_type'],
                    'item_id' => $data['item_id'] ?? null,
                    'item_code' => $data['item_code'],
                    'item_name' => $data['item_name'] ?? null,
                    'period' => $period,
                    'quantity' => $data['quantity'],
                    'source' => PlanningPlanLine::Manual,
                    'notes' => $data['notes'] ?? null,
                ])->refresh();
            }

            $line->update(['quantity' => $data['quantity'], 'item_name' => $data['item_name'] ?? $line->item_name, 'notes' => $data['notes'] ?? $line->notes, 'source' => PlanningPlanLine::Manual]);

            return $line->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(PlanningPlanLine $line, array $data): PlanningPlanLine
    {
        $scenario = PlanningScenario::query()->findOrFail($line->scenario_id);
        $this->scenarios->assertDraft($scenario);

        $merged = [...$line->only(['line_type', 'item_code', 'item_name', 'item_id']), 'period' => $line->period->toDateString(), ...$data];
        $merged = $this->resolveItem($scenario, $merged);
        $period = $this->period($scenario, (string) $merged['period']);
        $other = $this->find($scenario, (string) $merged['line_type'], (string) $merged['item_code'], $period);

        if ($other !== null && $other->id !== $line->id) {
            throw ValidationException::withMessages(['item_code' => [__('planning_errors.line.duplicate')]]);
        }

        $line->update([
            'line_type' => $merged['line_type'],
            'item_id' => $merged['item_id'] ?? null,
            'item_code' => $merged['item_code'],
            'item_name' => $merged['item_name'] ?? null,
            'period' => $period,
            ...array_intersect_key($data, array_flip(['quantity', 'notes'])),
            'source' => PlanningPlanLine::Manual,
        ]);

        return $line->refresh();
    }

    public function delete(PlanningPlanLine $line): void
    {
        $this->scenarios->assertDraft(PlanningScenario::query()->findOrFail($line->scenario_id));
        $line->delete();
    }

    /**
     * Fills the demand side from the forecasts of the forecast source: forecasts of the same item and month are summed.
     * Lines already entered for an item and month are kept unless overwrite is set.
     *
     * @return array{created: int, updated: int, skipped: int}
     */
    public function importForecasts(PlanningScenario $scenario, bool $overwrite): array
    {
        return DB::transaction(function () use ($scenario, $overwrite): array {
            $this->scenarios->assertDraft($scenario);
            $from = PlanningPeriods::monthStart($scenario->horizon_start);
            $to = PlanningPeriods::monthStart($scenario->horizon_end)->endOfMonth();

            $sums = [];
            foreach ($this->forecasts->forecasts($scenario->tenant_id, $from, $to) as $forecast) {
                $key = $forecast['item_code']."\0".PlanningPeriods::monthStart($forecast['forecast_date'])->toDateString();
                $sums[$key] ??= ['item_id' => $forecast['item_id'], 'item_code' => $forecast['item_code'], 'item_name' => $forecast['item_name'], 'period' => PlanningPeriods::monthStart($forecast['forecast_date'])->toDateString(), 'quantity' => '0'];
                $sums[$key]['quantity'] = bcadd($sums[$key]['quantity'], $forecast['quantity'], 3);
            }

            $result = ['created' => 0, 'updated' => 0, 'skipped' => 0];

            foreach ($sums as $sum) {
                $line = $this->find($scenario, PlanningPlanLine::Demand, $sum['item_code'], $sum['period']);

                if ($line === null) {
                    PlanningPlanLine::create([
                        'tenant_id' => $scenario->tenant_id,
                        'scenario_id' => $scenario->id,
                        'line_type' => PlanningPlanLine::Demand,
                        'item_id' => $sum['item_id'],
                        'item_code' => $sum['item_code'],
                        'item_name' => $sum['item_name'],
                        'period' => $sum['period'],
                        'quantity' => $sum['quantity'],
                        'source' => PlanningPlanLine::Forecast,
                    ]);
                    $result['created']++;
                } elseif ($overwrite) {
                    $line->update(['quantity' => $sum['quantity'], 'source' => PlanningPlanLine::Forecast]);
                    $result['updated']++;
                } else {
                    $result['skipped']++;
                }
            }

            return $result;
        });
    }

    /**
     * An item given by id is completed from the item master (code and name); an item that is not in the master of the
     * company is refused.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolveItem(PlanningScenario $scenario, array $data): array
    {
        if (! empty($data['item_id'])) {
            $item = $this->forecasts->findItem($scenario->tenant_id, (string) $data['item_id']);

            if ($item === null) {
                throw ValidationException::withMessages(['item_id' => [__('planning_errors.line.item_missing')]]);
            }

            $data['item_code'] = $item['item_code'];
            $data['item_name'] = $data['item_name'] ?? $item['item_name'];
        } else {
            $data['item_id'] = null;
        }

        if (empty($data['item_code'])) {
            throw ValidationException::withMessages(['item_code' => [__('planning_errors.line.item_required')]]);
        }

        return $data;
    }

    private function period(PlanningScenario $scenario, string $date): string
    {
        $month = PlanningPeriods::monthStart($date);

        if (! PlanningPeriods::covers($scenario, $month)) {
            throw ValidationException::withMessages(['period' => [__('planning_errors.line.outside_horizon')]]);
        }

        return $month->toDateString();
    }

    private function find(PlanningScenario $scenario, string $type, string $itemCode, string $period): ?PlanningPlanLine
    {
        return PlanningPlanLine::query()
            ->where('scenario_id', $scenario->id)
            ->where('line_type', $type)
            ->where('item_code', $itemCode)
            ->whereDate('period', $period)
            ->first();
    }
}

<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Application\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\ArtisanalGelato\Domain\Events\GelatoLotMaterialsConsumed;
use Modules\ArtisanalGelato\Domain\Services\HaccpThermalCurveEvaluator;
use Modules\ArtisanalGelato\Domain\Services\RecipeBalancingEngine;
use Modules\ArtisanalGelato\Infrastructure\Models\GelatoLotMaterialConsumption;
use Modules\ArtisanalGelato\Infrastructure\Models\GelatoPasteurizationChurnLot;

/**
 * Pasteurization and churning lots. A base lot (pasteurized, aged at +4 C) feeds flavor lots (churned with pastes), and every
 * lot records the raw material lots and the parent base grams it consumed, so the genealogy runs from the raw lot to the tub.
 * A flavor lot can draw only from a completed base lot whose HACCP curve passed and only up to the grams still available.
 */
final class GelatoLotService
{
    public function __construct(private readonly HaccpThermalCurveEvaluator $haccp, private readonly RecipeBalancingEngine $engine) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{ingredient_id?: string|null, raw_lot_code?: string|null, source_lot_id?: string|null, grams: float|int|string}>  $consumptions
     */
    public function start(array $data, array $consumptions, ?string $userId): GelatoPasteurizationChurnLot
    {
        $parentId = $data['parent_lot_id'] ?? null;

        if ($data['kind'] === 'base' && $parentId !== null) {
            throw new InvalidArgumentException('A base lot cannot have a parent lot.');
        }

        return DB::transaction(function () use ($data, $consumptions, $parentId, $userId): GelatoPasteurizationChurnLot {
            $parentDraw = 0.0;

            foreach ($consumptions as $line) {
                if (! empty($line['source_lot_id'])) {
                    if ($line['source_lot_id'] !== $parentId) {
                        throw new InvalidArgumentException('Base grams can only be drawn from the parent lot of this lot.');
                    }

                    $parentDraw += (float) $line['grams'];
                }
            }

            if ($parentId !== null) {
                $this->assertParentUsable($parentId, $parentDraw);
            }

            $lot = GelatoPasteurizationChurnLot::query()->create([
                'lot_code' => $data['lot_code'] ?? GelatoRecipeService::lotCode($data['kind'] === 'base' ? 'BASE' : 'FLV'),
                'kind' => $data['kind'],
                'method' => $data['method'] ?? 'direct',
                'recipe_id' => $data['recipe_id'] ?? null,
                'parent_lot_id' => $parentId,
                'status' => 'in_progress',
                'operator_id' => $userId,
                'equipment' => $data['equipment'] ?? null,
                'mix_weight_grams' => $data['mix_weight_grams'] ?? 0,
                'mix_volume_liters' => $data['mix_volume_liters'] ?? 0,
                'started_at' => now(),
            ]);

            $this->recordConsumptions($lot, $consumptions);

            return $lot;
        });
    }

    /**
     * Adds readings to the thermal curve and re-evaluates it.
     *
     * @param  list<array{at: string, temperature_celsius: float|int|string, phase?: string|null}>  $readings
     */
    public function recordHaccp(string $lotId, array $readings): GelatoPasteurizationChurnLot
    {
        $lot = GelatoPasteurizationChurnLot::query()->findOrFail($lotId);

        if ($lot->status === 'completed') {
            throw new InvalidArgumentException('A completed lot cannot receive more readings.');
        }

        $all = [...(array) ($lot->haccp_readings ?? []), ...$readings];
        $evaluation = $this->haccp->evaluate($all, $lot->kind === 'base' || $lot->method === 'direct');
        $lot->forceFill(['haccp_readings' => $all, 'haccp_passed' => $evaluation['passed'], 'haccp_findings' => $evaluation['findings']])->save();

        return $lot;
    }

    /**
     * @param  array{gelato_volume_liters?: float|int|null, yield_grams?: float|int|null, yield_tubs?: int|null, aging_minutes?: int|null}  $result
     */
    public function complete(string $lotId, array $result): GelatoPasteurizationChurnLot
    {
        $lot = GelatoPasteurizationChurnLot::query()->findOrFail($lotId);

        if ($lot->status === 'completed') {
            throw new InvalidArgumentException('The lot is already completed.');
        }

        if ($lot->haccp_passed !== true) {
            throw new InvalidArgumentException('The HACCP thermal curve must pass before the lot can be completed.');
        }

        $overrun = null;

        if (! empty($result['gelato_volume_liters']) && (float) $lot->mix_volume_liters > 0) {
            $overrun = $this->engine->overrun((float) $result['gelato_volume_liters'], (float) $lot->mix_volume_liters);
        }

        $lot->forceFill([
            'status' => 'completed',
            'gelato_volume_liters' => $result['gelato_volume_liters'] ?? null,
            'overrun_percent' => $overrun,
            'yield_grams' => $result['yield_grams'] ?? null,
            'yield_tubs' => $result['yield_tubs'] ?? null,
            'aging_minutes' => $result['aging_minutes'] ?? null,
            'aged_since' => $lot->kind === 'base' ? now() : null,
            'completed_at' => now(),
        ])->save();

        return $lot;
    }

    /**
     * The genealogy of a lot: the lot, its raw material consumptions, its ancestors and its descendants.
     *
     * @return array<string, mixed>
     */
    public function genealogy(string $lotId): array
    {
        $lot = GelatoPasteurizationChurnLot::query()->findOrFail($lotId);

        return [
            'lot' => $this->node($lot),
            'ancestors' => $this->ancestors($lot),
            'descendants' => $this->descendants($lot->id),
        ];
    }

    /**
     * @param  list<array{ingredient_id?: string|null, raw_lot_code?: string|null, source_lot_id?: string|null, grams: float|int|string}>  $consumptions
     */
    private function recordConsumptions(GelatoPasteurizationChurnLot $lot, array $consumptions): void
    {
        foreach ($consumptions as $line) {
            GelatoLotMaterialConsumption::query()->create([
                'lot_id' => $lot->id,
                'ingredient_id' => $line['ingredient_id'] ?? null,
                'source_lot_id' => $line['source_lot_id'] ?? null,
                'raw_lot_code' => $line['raw_lot_code'] ?? null,
                'grams' => $line['grams'],
            ]);
        }

        // Stock deduction of the raw material lots is the job of the inventory listeners of this event.
        event(new GelatoLotMaterialsConsumed((string) $lot->id, array_values(array_filter($consumptions, static fn (array $line): bool => ! empty($line['ingredient_id'])))));
    }

    private function assertParentUsable(string $parentId, float $draw): void
    {
        $parent = GelatoPasteurizationChurnLot::query()->lockForUpdate()->find($parentId) ?? throw new InvalidArgumentException('Unknown parent lot.');

        if ($parent->status !== 'completed' || $parent->haccp_passed !== true) {
            throw new InvalidArgumentException('The parent base lot must be completed with a passed HACCP curve.');
        }

        $available = (float) $parent->yield_grams - (float) GelatoLotMaterialConsumption::query()->where('source_lot_id', $parentId)->sum('grams');

        if ($draw > $available + 0.0005) {
            throw new InvalidArgumentException('The parent base lot has only '.round($available, 2).' g left.');
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ancestors(GelatoPasteurizationChurnLot $lot): array
    {
        $chain = [];
        $guard = 0;

        while ($lot->parent_lot_id !== null && $guard++ < 10) {
            $lot = GelatoPasteurizationChurnLot::query()->find($lot->parent_lot_id);

            if ($lot === null) {
                break;
            }

            $chain[] = $this->node($lot);
        }

        return $chain;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function descendants(string $lotId, int $depth = 0): array
    {
        if ($depth > 10) {
            return [];
        }

        return GelatoPasteurizationChurnLot::query()->where('parent_lot_id', $lotId)->get()
            ->map(fn (GelatoPasteurizationChurnLot $child): array => $this->node($child) + ['descendants' => $this->descendants($child->id, $depth + 1)])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function node(GelatoPasteurizationChurnLot $lot): array
    {
        return [
            'id' => $lot->id,
            'lot_code' => $lot->lot_code,
            'kind' => $lot->kind,
            'status' => $lot->status,
            'recipe_id' => $lot->recipe_id,
            'yield_grams' => $lot->yield_grams,
            'consumptions' => GelatoLotMaterialConsumption::query()->where('lot_id', $lot->id)->get(['ingredient_id', 'source_lot_id', 'raw_lot_code', 'grams'])->toArray(),
        ];
    }
}

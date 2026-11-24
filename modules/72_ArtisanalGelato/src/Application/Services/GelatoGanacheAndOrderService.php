<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Application\Services;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\ArtisanalGelato\Domain\Contracts\ScalePort;
use Modules\ArtisanalGelato\Domain\Services\GanacheWaterActivityCalculator;
use Modules\ArtisanalGelato\Domain\Services\TemperingCurveCatalog;
use Modules\ArtisanalGelato\Infrastructure\Models\GelatoBalancedRecipe;
use Modules\ArtisanalGelato\Infrastructure\Models\GelatoChocolateGanacheSpec;
use Modules\ArtisanalGelato\Infrastructure\Models\GelatoOrder;
use Modules\ArtisanalGelato\Infrastructure\Models\GelatoScaleReading;

/**
 * Ganache studio (water activity, shelf life, tempering curve), praline box / cake / tub pre-orders and scale readings.
 */
final class GelatoGanacheAndOrderService
{
    public function __construct(
        private readonly GelatoRecipeService $recipes,
        private readonly GanacheWaterActivityCalculator $waterActivity,
        private readonly TemperingCurveCatalog $tempering,
        private readonly ScalePort $scale,
    ) {}

    /**
     * Water activity and shelf life of draft lines, without storing them.
     *
     * @param  list<array{ingredient_id?: string|null, sub_recipe_id?: string|null, grams: float|int|string}>  $lines
     * @return array<string, mixed>
     */
    public function calculate(array $lines): array
    {
        return $this->waterActivity->calculate($this->recipes->engineLines($lines));
    }

    /**
     * Stores (or refreshes) the ganache spec of a recipe from its stored lines and a measured tempering curve.
     *
     * @param  array{chocolate_type: string, cocoa_butter_percent?: float|int|null, cocoa_mass_percent?: float|int|null, tempering?: array{melt: float|int, cool: float|int, work: float|int}|null}  $data
     * @return array<string, mixed>
     */
    public function saveSpec(string $recipeId, array $data): array
    {
        $recipe = GelatoBalancedRecipe::query()->findOrFail($recipeId);
        $lines = $recipe->lines()->orderBy('position')->get()->map(static fn ($l): array => ['ingredient_id' => $l->ingredient_id, 'sub_recipe_id' => $l->sub_recipe_id, 'grams' => (float) $l->grams])->all();
        $result = $this->calculate($lines);
        $curve = $this->tempering->curve($data['chocolate_type']);
        $validation = isset($data['tempering']) ? $this->tempering->validate($data['chocolate_type'], $data['tempering']) : null;

        $spec = GelatoChocolateGanacheSpec::query()->firstOrNew(['recipe_id' => $recipeId]);
        $spec->fill([
            'chocolate_type' => $data['chocolate_type'],
            'cocoa_butter_percent' => $data['cocoa_butter_percent'] ?? 0,
            'cocoa_mass_percent' => $data['cocoa_mass_percent'] ?? 0,
            'water_percent' => $result['water_percent'],
            'water_activity' => $result['water_activity'],
            'shelf_life_days' => $result['shelf_life_days'],
            'risk_band' => $result['risk_band'],
            'tempering_curve' => ['reference' => $curve, 'measured' => $data['tempering'] ?? null, 'validation' => $validation],
        ])->save();

        return ['spec' => $spec->only(['id', 'recipe_id', 'chocolate_type', 'cocoa_butter_percent', 'cocoa_mass_percent', 'water_percent', 'water_activity', 'shelf_life_days', 'risk_band', 'tempering_curve']), 'calculation' => $result, 'tempering' => $validation];
    }

    /**
     * @param  array{kind: string, customer_name: string, customer_phone?: string|null, components: list<array<string, mixed>>, total_price: float|int, pickup_at?: string|null, notes?: string|null}  $data
     */
    public function createOrder(array $data): GelatoOrder
    {
        $cost = 0.0;

        foreach ($data['components'] as $component) {
            if (! empty($component['recipe_id'])) {
                $recipe = GelatoBalancedRecipe::query()->findOrFail($component['recipe_id']);
                $cost += (float) $recipe->food_cost_per_kg * (float) ($component['grams'] ?? 0) / 1000;
            } else {
                $cost += (float) ($component['unit_cost'] ?? 0) * (float) ($component['quantity'] ?? 1);
            }
        }

        return GelatoOrder::query()->create([
            'order_number' => 'GO-'.now()->format('ymd').'-'.Str::upper(Str::random(4)),
            'kind' => $data['kind'],
            'customer_name' => $data['customer_name'],
            'customer_phone' => $data['customer_phone'] ?? null,
            'components' => $data['components'],
            'total_price' => $data['total_price'],
            'component_cost' => round($cost, 2),
            'status' => 'pre_order',
            'pickup_at' => $data['pickup_at'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    public function updateOrderStatus(string $orderId, string $status): GelatoOrder
    {
        $order = GelatoOrder::query()->findOrFail($orderId);
        $flow = ['pre_order' => ['confirmed', 'cancelled'], 'confirmed' => ['ready', 'cancelled'], 'ready' => ['collected', 'cancelled'], 'collected' => [], 'cancelled' => []];

        if (! in_array($status, $flow[$order->status] ?? [], true)) {
            throw new InvalidArgumentException("An order {$order->status} cannot become {$status}.");
        }

        $order->forceFill(['status' => $status])->save();

        return $order;
    }

    /**
     * Stores a scale reading: the weight typed by the operator or, when none is given, the one of the connected scale.
     *
     * @param  array{grams?: float|int|string|null, lot_id?: string|null, ingredient_id?: string|null, device?: string|null}  $data
     */
    public function recordScaleReading(array $data): GelatoScaleReading
    {
        $grams = isset($data['grams']) ? (float) $data['grams'] : $this->scale->read($data['device'] ?? null);

        if ($grams === null) {
            throw new InvalidArgumentException('The scale has no stable reading: type the weight.');
        }

        return GelatoScaleReading::query()->create([
            'lot_id' => $data['lot_id'] ?? null,
            'ingredient_id' => $data['ingredient_id'] ?? null,
            'grams' => $grams,
            'source' => isset($data['grams']) ? 'manual' : $this->scale->source(),
            'device' => $data['device'] ?? null,
            'read_at' => now(),
        ]);
    }
}

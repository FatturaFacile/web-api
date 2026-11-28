<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\DairyCheeseManufacturing\Domain\Milk\Period;
use Modules\DairyCheeseManufacturing\Domain\Pricing\PricingEngine;
use Modules\DairyCheeseManufacturing\Domain\Pricing\PricingInput;
use Modules\DairyCheeseManufacturing\Exceptions\DairyCheeseException;
use Modules\DairyCheeseManufacturing\Models\Breeder;
use Modules\DairyCheeseManufacturing\Models\MilkDelivery;
use Modules\DairyCheeseManufacturing\Models\MonthlySettlement;
use Modules\DairyCheeseManufacturing\Models\PricingGrid;
use Modules\DairyCheeseManufacturing\Models\QualityTest;
use Modules\DairyCheeseManufacturing\Models\VatBatchMilkDraw;

/**
 * Monthly milk liquidation. Per breeder: the accepted deliveries of the month, the quality of the month (arithmetic mean of fat,
 * protein, casein, urea and freezing point; geometric mean of bacterial and somatic cell counts, as the milk payment schemes do),
 * priced with the grid. Deliveries in quarantine or rejected are not paid.
 */
final class SettlementService
{
    public function __construct(private readonly PricingEngine $engine = new PricingEngine) {}

    /**
     * @param  list<string>|null  $breederIds
     * @return list<MonthlySettlement>
     */
    public function generate(string $period, string $gridId, ?array $breederIds = null, ?string $userId = null): array
    {
        $start = Period::start($period);
        $end = $start->endOfMonth();
        $grid = PricingGrid::query()->findOrFail($gridId);

        if (! $grid->is_active || $grid->valid_from->gt($end) || ($grid->valid_to !== null && $grid->valid_to->lt($start))) {
            throw new DairyCheeseException('The pricing grid is not valid in the period.', 'grid_not_valid');
        }

        $query = MilkDelivery::query()->whereBetween('collection_date', [$start->toDateString(), $end->toDateString()])->whereIn('status', ['registered', 'accepted']);

        if ($breederIds !== null && $breederIds !== []) {
            $query->whereIn('breeder_id', $breederIds);
        }

        $byBreeder = $query->get()->groupBy('breeder_id');

        if ($byBreeder->isEmpty()) {
            throw new DairyCheeseException('There are no payable deliveries in the period.', 'no_deliveries');
        }

        return DB::transaction(function () use ($byBreeder, $period, $grid, $start, $end, $userId): array {
            $settlements = [];

            foreach ($byBreeder as $breederId => $deliveries) {
                $settlements[] = $this->settle(Breeder::query()->findOrFail((string) $breederId), $deliveries, $period, $grid, $start, $end, $userId);
            }

            return $settlements;
        });
    }

    public function approve(MonthlySettlement $settlement): MonthlySettlement
    {
        if ($settlement->status !== 'draft') {
            throw new DairyCheeseException('Only a draft settlement can be approved.', 'settlement_not_draft');
        }

        $settlement->forceFill(['status' => 'approved'])->save();

        return $settlement;
    }

    /**
     * @param  Collection<int, MilkDelivery>  $deliveries
     */
    private function settle(Breeder $breeder, Collection $deliveries, string $period, PricingGrid $grid, CarbonImmutable $start, CarbonImmutable $end, ?string $userId): MonthlySettlement
    {
        $existing = MonthlySettlement::query()->where('breeder_id', $breeder->id)->where('period', $period)->first();

        if ($existing !== null) {
            if ($existing->status !== 'draft') {
                throw new DairyCheeseException('The settlement of the breeder is already approved.', 'settlement_locked');
            }

            MilkDelivery::query()->where('monthly_settlement_id', $existing->id)->update(['monthly_settlement_id' => null]);
            $existing->forceDelete();
        }

        $tests = $this->tests($breeder, $start, $end);
        $totalKg = round((float) $deliveries->sum('kg'), 3);
        $totalLiters = round((float) $deliveries->sum('liters'), 3);
        $cheeseKg = min($totalKg, round((float) VatBatchMilkDraw::query()
            ->where('breeder_id', $breeder->id)
            ->whereHas('batch', static fn ($q) => $q->whereBetween('production_date', [$start->toDateString(), $end->toDateString()]))
            ->sum('kg'), 3));

        $fat = $this->mean($tests, 'fat_pct');
        $protein = $this->mean($tests, 'protein_pct');
        $input = new PricingInput(
            $totalKg, $cheeseKg, $fat, $protein, $this->mean($tests, 'casein_pct'), $this->mean($tests, 'urea_mg_l'),
            $this->geometricMean($tests, 'tbc_cfu_ml'), $this->geometricMean($tests, 'scc_cells_ml'), $this->mean($tests, 'freezing_point_c'),
            $tests->contains(static fn (QualityTest $t): bool => $t->inhibitors === 'positive'),
        );

        $jurisdiction = (string) $breeder->jurisdiction;
        $result = $this->engine->price(
            [
                'base_price_per_kg' => (string) $grid->base_price_per_kg,
                'cheese_milk_supplement_per_kg' => (string) $grid->cheese_milk_supplement_per_kg,
                'dop_premium_per_kg' => (string) $grid->dop_premium_per_kg,
                'tiers' => array_values((array) $grid->tiers),
            ],
            $input,
            (float) config('dairy_cheese.vat_rate_percent.'.$jurisdiction, 0.0),
        );

        $settlement = MonthlySettlement::query()->create([
            'breeder_id' => $breeder->id, 'period' => $period, 'grid_id' => $grid->id, 'status' => 'draft',
            'total_kg' => $totalKg, 'total_liters' => $totalLiters, 'avg_fat_pct' => $fat ?? 0, 'avg_protein_pct' => $protein ?? 0,
            'base_amount' => $result->base, 'quality_adjustment' => $result->bonus, 'supplement_amount' => $result->supplement, 'penalty_amount' => $result->penalty,
            'gross_amount' => $result->gross, 'vat_amount' => $result->vat, 'net_amount' => $result->net,
            'currency' => (string) config('dairy_cheese.currency.'.$jurisdiction, 'EUR'), 'lines' => $result->lines, 'created_by' => $userId,
        ]);

        MilkDelivery::query()->whereIn('id', $deliveries->pluck('id'))->update(['monthly_settlement_id' => $settlement->id]);

        return $settlement->load('breeder');
    }

    /**
     * The analyses of the month; without any, the latest one before it.
     *
     * @return Collection<int, QualityTest>
     */
    private function tests(Breeder $breeder, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $tests = QualityTest::query()->where('breeder_id', $breeder->id)->whereBetween('sample_date', [$start->toDateString(), $end->toDateString()])->get();

        if ($tests->isNotEmpty()) {
            return $tests;
        }

        $last = QualityTest::query()->where('breeder_id', $breeder->id)->whereDate('sample_date', '<', $start->toDateString())->orderByDesc('sample_date')->first();

        return $last === null ? new Collection : new Collection([$last]);
    }

    /**
     * @param  Collection<int, QualityTest>  $tests
     */
    private function mean(Collection $tests, string $field): ?float
    {
        $values = $tests->pluck($field)->filter(static fn ($v): bool => $v !== null)->map(static fn ($v): float => (float) $v);

        return $values->isEmpty() ? null : round((float) $values->avg(), 4);
    }

    /**
     * @param  Collection<int, QualityTest>  $tests
     */
    private function geometricMean(Collection $tests, string $field): ?float
    {
        $values = $tests->pluck($field)->filter(static fn ($v): bool => $v !== null)->map(static fn ($v): float => max(1.0, (float) $v));

        return $values->isEmpty() ? null : round(exp((float) $values->map(static fn (float $v): float => log($v))->avg()), 2);
    }
}

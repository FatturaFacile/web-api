<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Services\Regulatory;

use Carbon\CarbonImmutable;
use Modules\DairyCheeseManufacturing\Domain\Enums\Authority;
use Modules\DairyCheeseManufacturing\Domain\Milk\Period;
use Modules\DairyCheeseManufacturing\Models\Breeder;
use Modules\DairyCheeseManufacturing\Models\CheeseAgingWheel;
use Modules\DairyCheeseManufacturing\Models\CheeseVatBatch;
use Modules\DairyCheeseManufacturing\Models\MilkDelivery;
use Modules\DairyCheeseManufacturing\Models\QualityTest;
use Modules\DairyCheeseManufacturing\Models\VatBatchMilkDraw;

/**
 * Collects the movements of a month that an authority wants to see and checks that the declaration can be filed.
 *  - dbmilch (Switzerland, TSM Treuhand Stelle Milch): deliveries of the Swiss producers and the milk they contributed to the
 *    vats (Verkäste Milch, the basis of the cheese-milk supplement).
 *  - sian (Italy, Registro Telematico Latte, D.M. 27/05/2021): load of the milk of the Italian breeders, transformation, cheese
 *    produced and cheese discharged (sold).
 *
 * Issue codes may carry a reference after a colon (breeder_bdm_missing:BR-01); the frontend dictionary translates the code.
 */
final class MovementCollector
{
    /**
     * @return array{movements: list<array{date: string, type: string, description: string, quantity_kg: string, reference: string, breeder_code?: string|null}>, breeders: array<string, Breeder>, totals: array<string, string|int>, errors: list<string>, warnings: list<string>}
     */
    public function collect(Authority $authority, string $period): array
    {
        $errors = [];
        $warnings = [];

        if (! Period::isValid($period)) {
            return ['movements' => [], 'breeders' => [], 'totals' => $this->totals([], 0), 'errors' => ['period_invalid'], 'warnings' => []];
        }

        $start = Period::start($period);

        $end = $start->endOfMonth();

        if ($end->gte(CarbonImmutable::today())) {
            $errors[] = 'period_open';
        }

        $jurisdiction = $authority === Authority::Dbmilch ? 'CH' : 'IT';
        $breeders = Breeder::query()->where('jurisdiction', $jurisdiction)->get()->keyBy('id');
        $movements = [];

        $deliveries = MilkDelivery::query()->whereIn('breeder_id', $breeders->keys())->whereBetween('collection_date', [$start->toDateString(), $end->toDateString()])->where('status', '!=', 'rejected')->orderBy('collection_date')->get();

        foreach ($deliveries as $delivery) {
            $breeder = $breeders[$delivery->breeder_id];
            $movements[] = [
                'date' => $delivery->collection_date->toDateString(),
                'type' => 'milk_intake',
                'description' => $breeder->name.' ('.$delivery->session.')',
                'quantity_kg' => number_format((float) $delivery->kg, 3, '.', ''),
                'reference' => $breeder->code,
                'breeder_code' => $breeder->code,
            ];

            if ($delivery->status === 'quarantined') {
                $warnings[] = 'quarantined_milk:'.$breeder->code;
            }
        }

        $draws = VatBatchMilkDraw::query()->with('batch')->whereIn('breeder_id', $breeders->keys())
            ->whereHas('batch', static fn ($q) => $q->whereBetween('production_date', [$start->toDateString(), $end->toDateString()]))->get();
        $cheeseMilk = 0.0;

        foreach ($draws->groupBy('vat_batch_id') as $batchDraws) {
            /** @var CheeseVatBatch $batch */
            $batch = $batchDraws->first()->batch;
            $kg = round((float) $batchDraws->sum('kg'), 3);
            $cheeseMilk += $kg;
            $movements[] = [
                'date' => $batch->production_date->toDateString(), 'type' => 'milk_transformed', 'description' => $batch->cheese_type,
                'quantity_kg' => number_format($kg, 3, '.', ''), 'reference' => $batch->code, 'breeder_code' => null,
            ];

            if ($authority === Authority::Sian && $batch->fresh_yield_24h_kg !== null) {
                $movements[] = [
                    'date' => $batch->production_date->toDateString(), 'type' => 'cheese_produced', 'description' => $batch->cheese_type,
                    'quantity_kg' => number_format((float) $batch->fresh_yield_24h_kg, 3, '.', ''), 'reference' => $batch->code, 'breeder_code' => null,
                ];
            }
        }

        if ($authority === Authority::Sian) {
            $batchIds = VatBatchMilkDraw::query()->whereIn('breeder_id', $breeders->keys())->distinct()->pluck('vat_batch_id');
            $sold = CheeseAgingWheel::query()->with('batch')->whereIn('vat_batch_id', $batchIds)->whereBetween('sold_at', [$start->startOfDay(), $end->endOfDay()])->orderBy('sold_at')->get();

            foreach ($sold as $wheel) {
                $movements[] = [
                    'date' => $wheel->sold_at->toDateString(), 'type' => 'cheese_sold', 'description' => (string) $wheel->customer_name,
                    'quantity_kg' => number_format((float) $wheel->sold_weight_kg, 3, '.', ''), 'reference' => $wheel->serial, 'breeder_code' => null,
                ];
            }
        }

        usort($movements, static fn (array $a, array $b): int => [$a['date'], $a['type']] <=> [$b['date'], $b['type']]);

        if ($deliveries->isEmpty()) {
            $errors[] = 'no_movements';
        }

        $active = $breeders->only($deliveries->pluck('breeder_id')->unique()->all());

        foreach ($active as $breeder) {
            if ($authority === Authority::Dbmilch && ! filled($breeder->bdm_number) && ! filled($breeder->tvd_number)) {
                $errors[] = 'breeder_bdm_missing:'.$breeder->code;
            }

            if ($authority === Authority::Sian && ! filled($breeder->cuaa) && ! filled($breeder->asl_code)) {
                $errors[] = 'breeder_cuaa_missing:'.$breeder->code;
            }

            $hasTest = QualityTest::query()->where('breeder_id', $breeder->id)->whereBetween('sample_date', [$start->toDateString(), $end->toDateString()])->exists();

            if (! $hasTest) {
                $warnings[] = 'quality_test_missing:'.$breeder->code;
            }
        }

        return [
            'movements' => $movements,
            'breeders' => $active->all(),
            'totals' => $this->totals($movements, $deliveries->count(), $cheeseMilk, $active->count()),
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * @param  list<array{type: string, quantity_kg: string}>  $movements
     * @return array<string, string|int>
     */
    private function totals(array $movements, int $deliveries, float $cheeseMilk = 0.0, int $producers = 0): array
    {
        $sum = static fn (string $type): string => number_format(array_sum(array_map(static fn (array $m): float => $m['type'] === $type ? (float) $m['quantity_kg'] : 0.0, $movements)), 3, '.', '');

        return [
            'producers' => $producers,
            'deliveries' => $deliveries,
            'milk_delivered_kg' => $sum('milk_intake'),
            'cheese_milk_kg' => number_format($cheeseMilk, 3, '.', ''),
            'cheese_produced_kg' => $sum('cheese_produced'),
            'cheese_sold_kg' => $sum('cheese_sold'),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\DairyCheeseManufacturing\Exceptions\DairyCheeseException;
use Modules\DairyCheeseManufacturing\Models\AgingCareLog;
use Modules\DairyCheeseManufacturing\Models\AgingRack;
use Modules\DairyCheeseManufacturing\Models\CheeseAgingWheel;
use Modules\DairyCheeseManufacturing\Models\CheeseVatBatch;
use Modules\DairyCheeseManufacturing\Models\MilkTank;

/**
 * Vat batch production: milk withdrawal from the tanks (with the breeder composition), process log, completion with the
 * casein plaque range, the generation of the aging wheels and the yields.
 */
final class VatBatchService
{
    private const array CodePrefix = [
        'parmigiano_reggiano_dop' => 'PR', 'grana_padano_dop' => 'GP', 'gruyere_aop' => 'GR', 'sbrinz_aop' => 'SB', 'alpe_ticinese_dop' => 'AT', 'generic' => 'FM',
    ];

    public function __construct(private readonly TankInventoryService $tanks, private readonly WheelMetrics $metrics) {}

    /**
     * @param  array<string, mixed>  $data  cheese_type, production_date, vat_number, tank_usages[{tank_id, liters}]
     */
    public function create(array $data, ?string $userId = null): CheeseVatBatch
    {
        return DB::transaction(function () use ($data, $userId): CheeseVatBatch {
            $date = (string) $data['production_date'];
            $type = (string) $data['cheese_type'];
            $sequence = CheeseVatBatch::query()->whereDate('production_date', $date)->where('cheese_type', $type)->count() + 1;

            $batch = CheeseVatBatch::query()->create([
                'code' => sprintf('%s-%s-%02d', self::CodePrefix[$type] ?? 'FM', str_replace('-', '', $date), $sequence),
                'cheese_type' => $type,
                'status' => 'open',
                'production_date' => $date,
                'vat_number' => $data['vat_number'] ?? null,
                'created_by' => $userId,
            ]);

            $milkKg = 0.0;
            $seen = [];

            foreach ((array) $data['tank_usages'] as $usage) {
                $tankId = (string) $usage['tank_id'];

                if (isset($seen[$tankId])) {
                    throw new DairyCheeseException('A tank can be used once per vat.', 'tank_repeated');
                }

                $seen[$tankId] = true;
                $milkKg += $this->tanks->draw($batch, MilkTank::query()->findOrFail($tankId), (float) $usage['liters'])['kg'];
            }

            $batch->forceFill(['milk_kg' => round($milkKg, 3)])->save();

            return $this->fresh($batch);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function process(CheeseVatBatch $batch, array $data): CheeseVatBatch
    {
        if (! in_array($batch->status, ['open', 'in_production'], true)) {
            throw new DairyCheeseException('The batch is no longer in production.', 'batch_not_in_production');
        }

        $batch->forceFill([
            'status' => 'in_production',
            'starter_culture' => $data['starter_culture'] ?? $batch->starter_culture,
            'starter_amount' => $data['starter_amount'] ?? $batch->starter_amount,
            'rennet_type' => $data['rennet_type'] ?? $batch->rennet_type,
            'rennet_ml' => $data['rennet_ml'] ?? $batch->rennet_ml,
            'ph_log' => array_key_exists('ph_log', $data) ? array_values((array) $data['ph_log']) : $batch->ph_log,
            'curd_yield_kg' => $data['curd_yield_kg'] ?? $batch->curd_yield_kg,
        ])->save();

        return $this->fresh($batch);
    }

    /**
     * Closes the production: records the 24 h yield, assigns the plaque range and creates one wheel per plaque.
     *
     * @param  array<string, mixed>  $data
     */
    public function complete(CheeseVatBatch $batch, array $data): CheeseVatBatch
    {
        return DB::transaction(function () use ($batch, $data): CheeseVatBatch {
            if (! in_array($batch->status, ['open', 'in_production'], true)) {
                throw new DairyCheeseException('The batch is already completed.', 'batch_already_completed');
            }

            $from = (int) $data['plaque_from'];
            $to = (int) $data['plaque_to'];
            $count = $to - $from + 1;

            if ($count < 1 || $count > (int) config('dairy_cheese.max_wheels_per_batch', 500)) {
                throw new DairyCheeseException('The plaque range is not valid.', 'plaque_range_invalid');
            }

            $weights = $this->weights($data, $count);
            $prefix = (string) ($data['plaque_prefix'] ?? '');
            $serials = array_map(static fn (int $n): string => $prefix.str_pad((string) $n, 6, '0', STR_PAD_LEFT), range($from, $to));

            if (CheeseAgingWheel::query()->whereIn('serial', $serials)->exists()) {
                throw new DairyCheeseException('A plaque serial of the range is already assigned.', 'plaque_serial_taken');
            }

            $rack = isset($data['rack_id']) ? AgingRack::query()->findOrFail((string) $data['rack_id']) : null;

            if ($rack !== null) {
                $this->assertCapacity($rack, $count);
            }

            foreach ($serials as $i => $serial) {
                CheeseAgingWheel::query()->create([
                    'serial' => $serial, 'vat_batch_id' => $batch->id, 'rack_id' => $rack?->id, 'position' => $rack === null ? null : $i + 1,
                    'status' => $rack === null ? 'brine' : 'aging', 'grade' => 'ungraded', 'initial_weight_kg' => $weights[$i],
                ]);
            }

            $fresh = round((float) $data['fresh_yield_24h_kg'], 3);
            $milk = (float) $batch->milk_kg;

            $batch->forceFill([
                'status' => 'aging',
                'fresh_yield_24h_kg' => $fresh,
                'fresh_yield_24h_pct' => $milk > 0 ? round($fresh / $milk * 100, 3) : null,
                'plaque_prefix' => $prefix === '' ? null : $prefix,
                'plaque_from' => $from,
                'plaque_to' => $to,
                'wheels_count' => $count,
            ])->save();

            $this->scheduleCare($batch);
            $this->refreshYield($batch);

            return $this->fresh($batch);
        });
    }

    /**
     * Final aged yield: weight of the wheels (sale weight when sold, else current) over the milk of the vat.
     */
    public function refreshYield(CheeseVatBatch $batch): void
    {
        $batch->load('wheels.batch');
        $kg = 0.0;

        foreach ($batch->wheels as $wheel) {
            if ($wheel->status === 'sold' && $wheel->sold_weight_kg !== null) {
                $kg += (float) $wheel->sold_weight_kg;
            } else {
                $kg += $this->metrics->of($wheel)['current_kg'];
            }
        }

        $milk = (float) $batch->milk_kg;
        $batch->forceFill([
            'final_aged_yield_kg' => $batch->wheels->isEmpty() ? null : round($kg, 3),
            'final_aged_yield_pct' => $batch->wheels->isEmpty() || $milk <= 0 ? null : round($kg / $milk * 100, 3),
        ])->save();
    }

    public function assertCapacity(AgingRack $rack, int $additional): void
    {
        $occupied = CheeseAgingWheel::query()->where('rack_id', $rack->id)->whereIn('status', ['brine', 'aging'])->count();

        if ((int) $rack->capacity_wheels > 0 && $occupied + $additional > (int) $rack->capacity_wheels) {
            throw new DairyCheeseException('The shelf capacity would be exceeded.', 'rack_capacity_exceeded');
        }
    }

    public function fresh(CheeseVatBatch $batch): CheeseVatBatch
    {
        return $batch->refresh()->load(['tankUsages.tank', 'draws.breeder']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<float>
     */
    private function weights(array $data, int $count): array
    {
        if (isset($data['wheels']) && is_array($data['wheels']) && $data['wheels'] !== []) {
            if (count($data['wheels']) !== $count) {
                throw new DairyCheeseException('The wheels do not match the plaque range.', 'wheels_count_mismatch');
            }

            return array_map(static fn (array $w): float => round((float) $w['weight_kg'], 3), array_values($data['wheels']));
        }

        if (! isset($data['initial_weight_kg'])) {
            throw new DairyCheeseException('The weight of the wheels is required.', 'wheel_weight_required');
        }

        if (isset($data['wheels_count']) && (int) $data['wheels_count'] !== $count) {
            throw new DairyCheeseException('The wheels do not match the plaque range.', 'wheels_count_mismatch');
        }

        return array_fill(0, $count, round((float) $data['initial_weight_kg'], 3));
    }

    private function scheduleCare(CheeseVatBatch $batch): void
    {
        $date = CarbonImmutable::parse($batch->production_date);

        foreach ((array) config('dairy_cheese.care_schedule', []) as $type => $rule) {
            AgingCareLog::query()->create([
                'care_type' => $type, 'status' => 'scheduled', 'scope' => 'batch', 'target_id' => $batch->id,
                'scheduled_for' => $date->addDays((int) $rule['first_after_days'])->toDateString(),
            ]);
        }
    }
}

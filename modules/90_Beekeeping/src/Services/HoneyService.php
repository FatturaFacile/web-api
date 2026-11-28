<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Beekeeping\Events\JarringRunRecorded;
use Modules\Beekeeping\Exceptions\BeekeepingException;
use Modules\Beekeeping\Models\Apiary;
use Modules\Beekeeping\Models\ExtractionBatch;
use Modules\Beekeeping\Models\Hive;
use Modules\Beekeeping\Models\JarringRun;
use Modules\Beekeeping\Models\SuperIntake;

/**
 * Honey room workflow: super intake, extraction batch (HACCP lot), maturation analysis, jarring with stock deduction and TMC.
 * Every harvest and extraction is refused while the apiary has hives in a varroa withdrawal period.
 */
final class HoneyService
{
    public function __construct(private readonly WithdrawalGuard $guard, private readonly PackagingStock $stock) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function recordIntake(array $data): SuperIntake
    {
        $this->guard->assertCanHarvest((string) $data['apiary_id'], CarbonImmutable::parse((string) $data['harvested_on']));

        $intake = new SuperIntake($data);
        $intake->save();

        return $intake;
    }

    /**
     * Opens an extraction batch from supers not yet extracted.
     *
     * @param  array<string, mixed>  $data
     */
    public function createBatch(array $data): ExtractionBatch
    {
        /** @var list<string> $intakeIds */
        $intakeIds = array_values(array_unique((array) $data['intake_ids']));
        $extractedOn = CarbonImmutable::parse((string) $data['extracted_on']);
        $intakes = SuperIntake::query()->whereIn('id', $intakeIds)->get();

        if ($intakes->count() !== count($intakeIds)) {
            throw new BeekeepingException('A super intake does not exist.', 'intake_not_found', 404);
        }

        if ($intakes->contains(static fn (SuperIntake $intake): bool => $intake->extraction_batch_id !== null)) {
            throw new BeekeepingException('A super intake already belongs to a batch.', 'intake_already_extracted');
        }

        if ($intakes->pluck('botanical_origin')->unique()->count() > 1 && ! isset($data['botanical_origin'])) {
            throw new BeekeepingException('The supers have different botanical origins: choose the origin of the batch (millefiori).', 'intake_origin_mixed');
        }

        foreach ($intakes->pluck('apiary_id')->unique() as $apiaryId) {
            $this->guard->assertCanHarvest((string) $apiaryId, $extractedOn);
        }

        return DB::transaction(function () use ($data, $intakes, $extractedOn): ExtractionBatch {
            $origin = (string) ($data['botanical_origin'] ?? $intakes->first()?->botanical_origin);
            $hives = (int) ($data['hives_harvested'] ?? Hive::query()->whereIn('apiary_id', $intakes->pluck('apiary_id'))->whereIn('status', ['active', 'orphaned', 'queenless_cell'])->count());

            $batch = new ExtractionBatch([
                'lot_code' => $this->nextLotCode($extractedOn, $origin),
                'extracted_on' => $extractedOn->toDateString(),
                'botanical_origin' => $origin,
                'extracted_kg' => $data['extracted_kg'] ?? $intakes->sum(static fn (SuperIntake $intake): float => (float) $intake->net_weight_kg),
                'hives_harvested' => $hives,
                'tank_code' => $data['tank_code'] ?? null,
                'process_notes' => $data['process_notes'] ?? null,
            ]);
            $batch->save();

            SuperIntake::query()->whereIn('id', $intakes->pluck('id'))->update(['extraction_batch_id' => $batch->id]);

            return $batch;
        });
    }

    /**
     * Maturation and laboratory analysis (maturatore / fusto, moisture, HMF).
     *
     * @param  array<string, mixed>  $data
     */
    public function recordAnalysis(ExtractionBatch $batch, array $data): ExtractionBatch
    {
        $batch->fill(array_intersect_key($data, array_flip(['tank_code', 'decanted_on', 'moisture_pct', 'hmf_mg_kg', 'organoleptic_notes'])));
        $batch->save();

        return $batch;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function recordJarring(ExtractionBatch $batch, array $data): JarringRun
    {
        if (! $batch->quality()->isMarketable()) {
            throw new BeekeepingException('The batch exceeds a statutory limit and cannot be jarred as honey.', 'batch_not_marketable');
        }

        $format = (int) $data['jar_format_g'];
        $jars = (int) $data['jars_count'];
        $netKg = round($format * $jars / 1000, 2);
        $jarredOn = CarbonImmutable::parse((string) $data['jarred_on']);
        $remaining = (float) $batch->extracted_kg - (float) JarringRun::query()->where('extraction_batch_id', $batch->id)->sum('net_kg');

        if ($netKg > $remaining + 0.0001) {
            throw new BeekeepingException('The batch does not hold enough honey for this run.', 'batch_exhausted', 422, ['remaining' => number_format(max(0, $remaining), 2, '.', '')]);
        }

        return DB::transaction(function () use ($batch, $data, $format, $jars, $netKg, $jarredOn): JarringRun {
            $apiaryCountry = Apiary::query()->whereIn('id', SuperIntake::query()->where('extraction_batch_id', $batch->id)->pluck('apiary_id'))->value('country');
            $sequence = JarringRun::query()->withTrashed()->where('extraction_batch_id', $batch->id)->count() + 1;

            $run = new JarringRun([
                'extraction_batch_id' => $batch->id,
                'jar_lot_code' => sprintf('%s-%d-%02d', $batch->lot_code, $format, $sequence),
                'jarred_on' => $jarredOn->toDateString(),
                'jar_format_g' => $format,
                'jars_count' => $jars,
                'net_kg' => $netKg,
                'best_before' => $jarredOn->addMonths((int) config('beekeeping.best_before_months'))->toDateString(),
                'origin_country' => (string) ($data['origin_country'] ?? $apiaryCountry ?? 'IT'),
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'jar_product_id' => $data['jar_product_id'] ?? null,
                'cap_product_id' => $data['cap_product_id'] ?? null,
                'label_product_id' => $data['label_product_id'] ?? null,
            ]);
            $run->save();

            $run->stock_movements = $this->stock->consume($run);
            $run->save();

            event(new JarringRunRecorded($run->id, $batch->id, $run->jar_lot_code));

            return $run;
        });
    }

    private function nextLotCode(CarbonImmutable $date, string $origin): string
    {
        $prefix = sprintf('HNY-%d-%s-', $date->year, Str::upper(Str::substr($origin, 0, 3)));
        $sequence = ExtractionBatch::query()->withTrashed()->where('lot_code', 'like', $prefix.'%')->count() + 1;

        return $prefix.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
    }
}

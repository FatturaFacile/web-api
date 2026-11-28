<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Services;

use Carbon\CarbonImmutable;
use Modules\Beekeeping\Exceptions\BeekeepingException;
use Modules\Beekeeping\Models\Apiary;
use Modules\Beekeeping\Models\ExtractionBatch;
use Modules\Beekeeping\Models\JarringRun;
use Modules\Beekeeping\Models\SuperIntake;
use Modules\Beekeeping\Models\VarroaTreatment;

/**
 * HACCP traceability: forward (apiary or batch to the finished jar lots) and backward (jar lot to batch, supers, apiaries and the last
 * veterinary treatments before the harvest).
 */
final class TraceabilityService
{
    /**
     * @return array<string, mixed>
     */
    public function backward(string $jarLotCode): array
    {
        $run = JarringRun::query()->with('batch')->where('jar_lot_code', $jarLotCode)->first();

        if ($run === null || $run->batch === null) {
            throw new BeekeepingException('Unknown jar lot.', 'jar_lot_not_found', 404);
        }

        return [
            'direction' => 'backward',
            'jar_lot' => $this->jarLot($run),
            'batch' => $this->batchNode($run->batch, true),
        ];
    }

    /**
     * @param  'apiary'|'batch'|'intake'  $type
     * @return array<string, mixed>
     */
    public function forward(string $type, string $id): array
    {
        return match ($type) {
            'apiary' => $this->forwardApiary($id),
            'batch' => ['direction' => 'forward', 'batches' => [$this->batchNode(ExtractionBatch::query()->findOrFail($id), false)]],
            default => $this->forwardIntake($id),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function forwardApiary(string $apiaryId): array
    {
        $apiary = Apiary::query()->findOrFail($apiaryId);
        $batchIds = SuperIntake::query()->where('apiary_id', $apiaryId)->whereNotNull('extraction_batch_id')->pluck('extraction_batch_id')->unique();
        $batches = ExtractionBatch::query()->whereIn('id', $batchIds)->orderBy('extracted_on')->get();

        return [
            'direction' => 'forward',
            'apiary' => ['id' => $apiary->id, 'code' => $apiary->code, 'name' => $apiary->name],
            'batches' => $batches->map(fn (ExtractionBatch $batch): array => $this->batchNode($batch, false))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function forwardIntake(string $intakeId): array
    {
        $intake = SuperIntake::query()->findOrFail($intakeId);

        return [
            'direction' => 'forward',
            'intake' => $this->intakeNode($intake),
            'batches' => $intake->extraction_batch_id === null ? [] : [$this->batchNode(ExtractionBatch::query()->findOrFail($intake->extraction_batch_id), false)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function batchNode(ExtractionBatch $batch, bool $withTreatments): array
    {
        $quality = $batch->quality();

        return [
            'id' => $batch->id,
            'lot_code' => $batch->lot_code,
            'extracted_on' => $batch->extracted_on->toDateString(),
            'botanical_origin' => $batch->botanical_origin,
            'extracted_kg' => $batch->extracted_kg,
            'tank_code' => $batch->tank_code,
            'moisture_pct' => $batch->moisture_pct,
            'hmf_mg_kg' => $batch->hmf_mg_kg,
            'quality_warnings' => $quality->warnings(),
            'intakes' => $batch->intakes()->orderBy('harvested_on')->get()->map(fn (SuperIntake $intake): array => $this->intakeNode($intake, $withTreatments))->all(),
            'jar_lots' => $batch->jarringRuns()->orderBy('jarred_on')->get()->map(fn (JarringRun $run): array => $this->jarLot($run))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function intakeNode(SuperIntake $intake, bool $withTreatments = false): array
    {
        $apiary = Apiary::query()->find($intake->apiary_id);
        $node = [
            'id' => $intake->id,
            'harvested_on' => $intake->harvested_on->toDateString(),
            'botanical_origin' => $intake->botanical_origin,
            'supers_count' => $intake->supers_count,
            'net_weight_kg' => $intake->net_weight_kg,
            'apiary' => $apiary === null ? null : ['id' => $apiary->id, 'code' => $apiary->code, 'name' => $apiary->name, 'registry_code' => $apiary->registry_code],
        ];

        if ($withTreatments) {
            $node['last_treatments'] = VarroaTreatment::query()
                ->where('apiary_id', $intake->apiary_id)
                ->whereDate('treatment_date', '<=', $intake->harvested_on->toDateString())
                ->orderByDesc('treatment_date')
                ->limit(3)
                ->get()
                ->map(static fn (VarroaTreatment $treatment): array => [
                    'id' => $treatment->id,
                    'product' => $treatment->product,
                    'treatment_date' => $treatment->treatment_date->toDateString(),
                    'withdrawal_end_date' => $treatment->withdrawal_end_date->toDateString(),
                    'drug_batch' => $treatment->drug_batch,
                    'withdrawal_respected' => CarbonImmutable::parse($intake->harvested_on)->greaterThanOrEqualTo(CarbonImmutable::parse($treatment->withdrawal_end_date)),
                ])->all();
        }

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    private function jarLot(JarringRun $run): array
    {
        return [
            'id' => $run->id,
            'jar_lot_code' => $run->jar_lot_code,
            'jarred_on' => $run->jarred_on->toDateString(),
            'jar_format_g' => $run->jar_format_g,
            'jars_count' => $run->jars_count,
            'best_before' => $run->best_before->toDateString(),
            'origin_country' => $run->origin_country,
        ];
    }
}

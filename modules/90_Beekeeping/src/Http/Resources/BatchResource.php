<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Beekeeping\Models\ExtractionBatch;

/**
 * @mixin ExtractionBatch
 */
final class BatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lot_code' => $this->lot_code,
            'extracted_on' => $this->extracted_on->toDateString(),
            'botanical_origin' => $this->botanical_origin,
            'extracted_kg' => $this->extracted_kg,
            'hives_harvested' => $this->hives_harvested,
            'yield_per_hive_kg' => $this->yieldPerHiveKg(),
            'tank_code' => $this->tank_code,
            'decanted_on' => $this->decanted_on?->toDateString(),
            'moisture_pct' => $this->moisture_pct,
            'hmf_mg_kg' => $this->hmf_mg_kg,
            'moisture_status' => $this->quality()->moistureStatus(),
            'hmf_status' => $this->quality()->hmfStatus(),
            'quality_warnings' => $this->quality()->warnings(),
            'is_marketable' => $this->quality()->isMarketable(),
            'organoleptic_notes' => $this->organoleptic_notes,
            'process_notes' => $this->process_notes,
            'jarred_kg' => $this->whenLoaded('jarringRuns', fn (): float => round((float) $this->jarringRuns->sum('net_kg'), 2)),
            'intakes' => $this->whenLoaded('intakes', fn () => IntakeResource::collection($this->intakes)),
            'jarring_runs' => $this->whenLoaded('jarringRuns', fn () => JarringRunResource::collection($this->jarringRuns)),
        ];
    }
}

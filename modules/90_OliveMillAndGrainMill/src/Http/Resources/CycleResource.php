<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Http\Resources;

use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\OliveMillAndGrainMill\Models\MillingCycle;

/**
 * @mixin MillingCycle
 */
final class CycleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'delivery_id' => $this->delivery_id,
            'line_code' => $this->line_code,
            'status' => $this->status->value,
            'started_at' => $this->started_at->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'malaxation_temperature_c' => $this->malaxation_temperature_c,
            'water_added_l' => $this->water_added_l,
            'input_net_kg' => $this->input_net_kg,
            'output_kg' => $this->output_kg,
            'output_liters' => $this->output_liters,
            'density_kg_per_l' => $this->density_kg_per_l,
            'yield_percent' => $this->yield_percent,
            'cold_extraction' => $this->cold_extraction,
            'acidity_pct' => $this->acidity_pct,
            'peroxides' => $this->peroxides,
            'polyphenols_mg_kg' => $this->polyphenols_mg_kg,
            'panel_notes' => $this->panel_notes,
            'quality_class' => $this->quality_class,
            'quality_warnings' => $this->quality_warnings,
            'sansa_kg' => $this->sansa_kg,
            'nocciolino_kg' => $this->nocciolino_kg,
            'vegetation_water_l' => $this->vegetation_water_l,
            'bran_kg' => $this->bran_kg,
            'pickup_kg' => $this->pickup_kg,
            'molenda_kg' => $this->molenda_kg,
            'mill_stock_kg' => $this->mill_stock_kg,
            'target_tank_id' => $this->target_tank_id,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'lot_code' => $this->whenLoaded('delivery', fn (): string => $this->delivery->lot_code),
            'commodity' => $this->whenLoaded('delivery', fn (): string => $this->delivery->commodity->value),
            'processing_mode' => $this->whenLoaded('delivery', fn (): string => $this->delivery->processing_mode->value),
            'certification' => $this->whenLoaded('delivery', fn (): string => $this->delivery->certification->value),
            'farmer_name' => $this->whenLoaded('delivery', fn (): ?string => $this->delivery->relationLoaded('farmer') ? $this->farmerName($this->delivery->farmer) : null),
            'settlement_id' => $this->whenLoaded('settlement', fn (): ?string => $this->settlement?->id),
        ];
    }

    private function farmerName(?Contact $farmer): ?string
    {
        if ($farmer === null) {
            return null;
        }

        $company = trim((string) $farmer->company_name);

        return $company !== '' ? $company : trim($farmer->first_name.' '.$farmer->last_name);
    }
}

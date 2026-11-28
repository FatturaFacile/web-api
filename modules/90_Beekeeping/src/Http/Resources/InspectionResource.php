<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Beekeeping\Models\Inspection;

/**
 * @mixin Inspection
 */
final class InspectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hive_id' => $this->hive_id,
            'apiary_id' => $this->apiary_id,
            'inspected_at' => $this->inspected_at->toIso8601String(),
            'operator_id' => $this->operator_id,
            'weather' => $this->weather,
            'temperature_c' => $this->temperature_c,
            'queen_seen' => $this->queen_seen,
            'eggs_present' => $this->eggs_present,
            'larvae_present' => $this->larvae_present,
            'capped_brood_present' => $this->capped_brood_present,
            'brood_frames' => $this->brood_frames,
            'store_frames' => $this->store_frames,
            'supers_added' => $this->supers_added,
            'supers_removed' => $this->supers_removed,
            'swarm_cells_found' => $this->swarm_cells_found,
            'swarm_cells_destroyed' => $this->swarm_cells_destroyed,
            'feeding_type' => $this->feeding_type,
            'feeding_kg' => $this->feeding_kg,
            'strength_score' => $this->strength_score,
            'observations' => $this->observations,
            'alerts' => $this->alerts ?? [],
        ];
    }
}

<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ArtisanalGelato\Infrastructure\Models\GelatoPasteurizationChurnLot;

/**
 * @mixin GelatoPasteurizationChurnLot
 */
final class GelatoLotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource->only(['id', 'lot_code', 'kind', 'method', 'recipe_id', 'parent_lot_id', 'status', 'operator_id', 'equipment', 'mix_weight_grams', 'mix_volume_liters', 'gelato_volume_liters', 'overrun_percent', 'yield_grams', 'yield_tubs', 'aged_since', 'aging_minutes', 'haccp_readings', 'haccp_passed', 'haccp_findings', 'started_at', 'completed_at']);
    }
}

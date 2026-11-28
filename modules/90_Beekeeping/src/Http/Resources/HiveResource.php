<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Beekeeping\Models\Hive;
use Modules\Beekeeping\Services\ColonyAlertService;

/**
 * @mixin Hive
 */
final class HiveResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'apiary_id' => $this->apiary_id,
            'code' => $this->code,
            'qr_token' => $this->qr_token,
            'hive_type' => $this->hive_type,
            'origin' => $this->origin,
            'status' => $this->status,
            'is_nucleus' => $this->is_nucleus,
            'queen_year' => $this->queen_year,
            'queen_marking_color' => $this->queen_marking_color,
            'queen_clipped' => $this->queen_clipped,
            'queen_genetic_line' => $this->queen_genetic_line,
            'queen_breeder' => $this->queen_breeder,
            'queen_pedigree_ref' => $this->queen_pedigree_ref,
            'queen_status' => $this->queen_status,
            'brood_frames' => $this->brood_frames,
            'store_frames' => $this->store_frames,
            'supers_count' => $this->supers_count,
            'strength_score' => $this->strength_score,
            'temperament' => $this->temperament,
            'installed_on' => $this->installed_on?->toDateString(),
            'notes' => $this->notes,
            'apiary' => $this->whenLoaded('apiary', fn (): array => ['id' => $this->apiary->id, 'code' => $this->apiary->code, 'name' => $this->apiary->name]),
            'withdrawal_lock' => $this->resource->getAttribute('withdrawal_lock'),
            'alerts' => app(ColonyAlertService::class)->alerts(['brood_frames' => $this->brood_frames, 'supers_count' => $this->supers_count, 'strength_score' => $this->strength_score, 'queen_status' => $this->queen_status, 'queen_year' => $this->queen_year]),
        ];
    }
}

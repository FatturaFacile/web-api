<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DairyCheeseManufacturing\Models\QualityTest;
use Modules\DairyCheeseManufacturing\Services\QualityTestService;

/**
 * @mixin QualityTest
 */
final class QualityTestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'breeder_id' => $this->breeder_id, 'breeder_name' => $this->breeder->name, 'delivery_id' => $this->delivery_id,
            'sample_date' => $this->sample_date->toDateString(), 'period_start' => $this->period_start?->toDateString(), 'period_end' => $this->period_end?->toDateString(),
            'fat_pct' => $this->fat_pct, 'protein_pct' => $this->protein_pct, 'casein_pct' => $this->casein_pct, 'urea_mg_l' => $this->urea_mg_l,
            'tbc_cfu_ml' => $this->tbc_cfu_ml, 'scc_cells_ml' => $this->scc_cells_ml, 'freezing_point_c' => $this->freezing_point_c, 'inhibitors' => $this->inhibitors,
            'laboratory' => $this->laboratory, 'source_format' => $this->source_format, 'flags' => app(QualityTestService::class)->flags($this->resource),
        ];
    }
}

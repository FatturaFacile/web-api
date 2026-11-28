<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DairyCheeseManufacturing\Models\WeightLossCurve;

/**
 * @mixin WeightLossCurve
 */
final class CurveResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'cheese_type' => $this->cheese_type, 'name' => $this->name, 'is_template' => $this->tenant_id === null,
            'points' => array_values(array_map(static fn (array $p): array => ['age_months' => (float) $p['age_months'], 'loss_pct' => (float) $p['loss_pct']], (array) $this->points)),
        ];
    }
}

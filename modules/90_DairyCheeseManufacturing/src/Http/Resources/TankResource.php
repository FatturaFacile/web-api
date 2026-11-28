<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DairyCheeseManufacturing\Models\MilkTank;
use Modules\DairyCheeseManufacturing\Services\TankInventoryService;

/**
 * @mixin MilkTank
 */
final class TankResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $volume = app(TankInventoryService::class)->volume($this->id);

        return [
            'id' => $this->id, 'code' => $this->code, 'name' => $this->name, 'capacity_liters' => $this->capacity_liters,
            'current_volume_liters' => number_format($volume['liters'], 3, '.', ''), 'current_weight_kg' => number_format($volume['kg'], 3, '.', ''),
            'status' => $this->status, 'last_cleaned_at' => $this->last_cleaned_at?->toIso8601String(),
        ];
    }
}

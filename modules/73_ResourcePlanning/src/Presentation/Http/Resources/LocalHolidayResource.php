<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ResourcePlanning\Infrastructure\Models\LocalHoliday;

/**
 * @mixin LocalHoliday
 */
final class LocalHolidayResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'country' => $this->country,
            'region_code' => $this->region_code,
            'municipality' => $this->municipality,
            'month' => $this->month,
            'day' => $this->day,
            'year' => $this->year,
            'name' => $this->name,
            'day_fraction' => (float) $this->day_fraction,
            'is_generic' => $this->tenant_id === null,
        ];
    }
}

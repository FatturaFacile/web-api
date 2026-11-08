<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Treasury\Models\ForecastScenario;
use OpenApi\Attributes as OA;

/**
 * A liquidity forecast scenario and its assumptions.
 *
 * @mixin ForecastScenario
 */
#[OA\Schema(
    schema: 'TreasuryForecastScenarioResource',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'code', type: 'string'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'collection_delay_days', type: 'integer'),
        new OA\Property(property: 'disbursement_acceleration_days', type: 'integer'),
        new OA\Property(property: 'sales_growth_factor', type: 'string'),
        new OA\Property(property: 'is_active', type: 'boolean'),
    ]
)]
final class ForecastScenarioResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'collection_delay_days' => (int) $this->collection_delay_days,
            'disbursement_acceleration_days' => (int) $this->disbursement_acceleration_days,
            'sales_growth_factor' => (string) $this->sales_growth_factor,
            'is_active' => (bool) $this->is_active,
        ];
    }
}

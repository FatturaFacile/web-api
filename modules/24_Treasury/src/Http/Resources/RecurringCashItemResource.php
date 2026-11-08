<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Treasury\Models\RecurringCashItem;
use OpenApi\Attributes as OA;

/**
 * A recurring cash flow of the liquidity forecast.
 *
 * @mixin RecurringCashItem
 */
#[OA\Schema(
    schema: 'TreasuryRecurringCashItemResource',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'category', type: 'string'),
        new OA\Property(property: 'direction', type: 'string', enum: ['inflow', 'outflow']),
        new OA\Property(property: 'amount', type: 'string'),
        new OA\Property(property: 'currency', type: 'string'),
        new OA\Property(property: 'frequency', type: 'string'),
        new OA\Property(property: 'next_date', type: 'string', format: 'date'),
        new OA\Property(property: 'end_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean'),
    ]
)]
final class RecurringCashItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category,
            'direction' => $this->direction,
            'amount' => (string) $this->amount,
            'currency' => $this->currency,
            'frequency' => $this->frequency,
            'next_date' => $this->next_date->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'is_active' => (bool) $this->is_active,
        ];
    }
}

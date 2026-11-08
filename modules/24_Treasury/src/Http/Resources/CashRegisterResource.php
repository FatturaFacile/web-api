<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Treasury\Models\CashRegister;
use OpenApi\Attributes as OA;

/**
 * A cash register with its balance and the last closed day.
 *
 * @mixin CashRegister
 */
#[OA\Schema(
    schema: 'TreasuryCashRegisterResource',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'code', type: 'string'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'currency', type: 'string'),
        new OA\Property(property: 'gl_account_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'opening_balance', type: 'string'),
        new OA\Property(property: 'current_balance', type: 'string'),
        new OA\Property(property: 'last_closed_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean'),
    ]
)]
final class CashRegisterResource extends JsonResource
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
            'currency' => $this->currency,
            'gl_account_id' => $this->gl_account_id,
            'opening_balance' => (string) $this->opening_balance,
            'current_balance' => (string) $this->current_balance,
            'last_closed_date' => $this->last_closed_date?->toDateString(),
            'is_active' => (bool) $this->is_active,
        ];
    }
}

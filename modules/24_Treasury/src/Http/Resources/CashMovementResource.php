<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Treasury\Models\CashMovement;
use OpenApi\Attributes as OA;

/**
 * A receipt or payment of a cash register with the entry that posted it.
 *
 * @mixin CashMovement
 */
#[OA\Schema(
    schema: 'TreasuryCashMovementResource',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'cash_register_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'movement_date', type: 'string', format: 'date'),
        new OA\Property(property: 'direction', type: 'string', enum: ['in', 'out']),
        new OA\Property(property: 'payment_method', type: 'string', enum: ['cash', 'cheque']),
        new OA\Property(property: 'amount', type: 'string'),
        new OA\Property(property: 'counter_account_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'reference', type: 'string', nullable: true),
        new OA\Property(property: 'journal_entry_id', type: 'string', format: 'uuid', nullable: true),
    ]
)]
final class CashMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cash_register_id' => $this->cash_register_id,
            'movement_date' => $this->movement_date->toDateString(),
            'direction' => $this->direction,
            'payment_method' => $this->payment_method,
            'amount' => (string) $this->amount,
            'counter_account_id' => $this->counter_account_id,
            'description' => $this->description,
            'reference' => $this->reference,
            'journal_entry_id' => $this->journal_entry_id,
        ];
    }
}

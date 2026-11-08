<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Treasury\Models\TreasuryBankMovement;
use OpenApi\Attributes as OA;

/**
 * A treasury bank movement (transfer, bank fee, interest) with the entry that posted it. Amounts are decimal strings.
 *
 * @mixin TreasuryBankMovement
 */
#[OA\Schema(
    schema: 'TreasuryBankMovementResource',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'movement_type', type: 'string', enum: ['transfer', 'bank_fee', 'interest_income', 'interest_expense']),
        new OA\Property(property: 'bank_account_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'counter_bank_account_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'amount', type: 'string'),
        new OA\Property(property: 'currency', type: 'string'),
        new OA\Property(property: 'movement_date', type: 'string', format: 'date'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'idempotency_key', type: 'string'),
        new OA\Property(property: 'journal_entry_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
final class TreasuryBankMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'movement_type' => $this->movement_type,
            'bank_account_id' => $this->bank_account_id,
            'counter_bank_account_id' => $this->counter_bank_account_id,
            'amount' => (string) $this->amount,
            'currency' => $this->currency,
            'movement_date' => $this->movement_date->toDateString(),
            'description' => $this->description,
            'idempotency_key' => $this->idempotency_key,
            'journal_entry_id' => $this->journal_entry_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

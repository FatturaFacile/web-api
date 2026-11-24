<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Treasury\Models\CashClosing;
use OpenApi\Attributes as OA;

/**
 * A daily closing of a cash register.
 *
 * @mixin CashClosing
 */
#[OA\Schema(
    schema: 'TreasuryCashClosingResource',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'cash_register_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'closing_date', type: 'string', format: 'date'),
        new OA\Property(property: 'opening_balance', type: 'string'),
        new OA\Property(property: 'total_in', type: 'string'),
        new OA\Property(property: 'total_out', type: 'string'),
        new OA\Property(property: 'expected_balance', type: 'string'),
        new OA\Property(property: 'counted_balance', type: 'string'),
        new OA\Property(property: 'difference', type: 'string'),
        new OA\Property(property: 'difference_journal_entry_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
    ]
)]
final class CashClosingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cash_register_id' => $this->cash_register_id,
            'closing_date' => $this->closing_date->toDateString(),
            'opening_balance' => (string) $this->opening_balance,
            'total_in' => (string) $this->total_in,
            'total_out' => (string) $this->total_out,
            'expected_balance' => (string) $this->expected_balance,
            'counted_balance' => (string) $this->counted_balance,
            'difference' => (string) $this->difference,
            'difference_journal_entry_id' => $this->difference_journal_entry_id,
            'notes' => $this->notes,
        ];
    }
}

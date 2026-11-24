<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\BankReconciliationRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * A reconciliation rule with its conditions and the categorisation it produces. Amounts are decimal strings.
 *
 * @mixin BankReconciliationRule
 */
#[OA\Schema(
    schema: 'BankingReconciliationRuleResource',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'priority', type: 'integer'),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'direction', type: 'string', nullable: true),
        new OA\Property(property: 'expected_amount', type: 'string', nullable: true),
        new OA\Property(property: 'amount_tolerance', type: 'string'),
        new OA\Property(property: 'min_amount', type: 'string', nullable: true),
        new OA\Property(property: 'max_amount', type: 'string', nullable: true),
        new OA\Property(property: 'reference_contains', type: 'string', nullable: true),
        new OA\Property(property: 'description_contains', type: 'string', nullable: true),
        new OA\Property(property: 'counterparty_iban', type: 'string', nullable: true),
        new OA\Property(property: 'counterparty_name_contains', type: 'string', nullable: true),
        new OA\Property(property: 'category', type: 'string'),
        new OA\Property(property: 'ledger_account_code', type: 'string', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
class BankReconciliationRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'priority' => $this->priority,
            'is_active' => $this->is_active,
            'direction' => $this->direction,
            'expected_amount' => $this->expected_amount,
            'amount_tolerance' => $this->amount_tolerance,
            'min_amount' => $this->min_amount,
            'max_amount' => $this->max_amount,
            'reference_contains' => $this->reference_contains,
            'description_contains' => $this->description_contains,
            'counterparty_iban' => $this->counterparty_iban,
            'counterparty_name_contains' => $this->counterparty_name_contains,
            'category' => $this->category,
            'ledger_account_code' => $this->ledger_account_code,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

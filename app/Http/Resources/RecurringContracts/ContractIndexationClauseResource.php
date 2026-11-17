<?php

declare(strict_types=1);

namespace App\Http\Resources\RecurringContracts;

use App\Models\RecurringContracts\ContractIndexationClause;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ContractIndexationClause
 */
class ContractIndexationClauseResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'recurring_contract_id' => $this->recurring_contract_id,
            'index_type' => $this->index_type,
            'base_index_value' => (string) $this->base_index_value,
            'base_index_period' => $this->base_index_period,
            'adjustment_frequency_months' => $this->adjustment_frequency_months,
            'pass_through_percent' => (string) ($this->pass_through_percent ?? '100.00'),
            'floor_percent' => $this->floor_percent !== null ? (string) $this->floor_percent : null,
            'cap_percent' => $this->cap_percent !== null ? (string) $this->cap_percent : null,
            'next_adjustment_date' => $this->next_adjustment_date?->toDateString(),
            'last_adjusted_at' => $this->last_adjusted_at?->toIso8601String(),
            'is_active' => (bool) $this->is_active,
        ];
    }
}

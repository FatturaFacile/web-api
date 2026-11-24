<?php

declare(strict_types=1);

namespace App\Http\Resources\RecurringContracts;

use App\Models\RecurringContracts\ContractIndexationLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ContractIndexationLog
 */
class ContractIndexationLogResource extends JsonResource
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
            'clause_id' => $this->clause_id,
            'period_from' => $this->period_from,
            'period_to' => $this->period_to,
            'old_index_value' => (string) $this->old_index_value,
            'new_index_value' => (string) $this->new_index_value,
            'raw_delta_percent' => (string) $this->raw_delta_percent,
            'applied_delta_percent' => (string) $this->applied_delta_percent,
            'old_recurring_total' => (string) $this->old_recurring_total,
            'new_recurring_total' => (string) $this->new_recurring_total,
            'applied_at' => $this->applied_at?->toIso8601String(),
            'applied_by_user_id' => $this->applied_by_user_id,
        ];
    }
}

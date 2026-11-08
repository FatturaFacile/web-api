<?php

declare(strict_types=1);

namespace Modules\Contracts\Http\Resources;

use App\Services\Tenancy\TenantClock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Contracts\Models\ContractObligation;

/**
 * API representation of an obligation or milestone of a contract.
 *
 * @mixin ContractObligation
 */
final class ContractObligationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'contract_kind' => $this->contract_kind,
            'contract_id' => (string) $this->contract_id,
            'type' => $this->type,
            'title' => $this->title,
            'description' => $this->description,
            'responsible_party' => $this->responsible_party,
            'owner_user_id' => $this->owner_user_id,
            'due_date' => $this->due_date->toDateString(),
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'is_overdue' => $this->isOverdue(app(TenantClock::class)->today()),
            'remind_days_before' => (int) $this->remind_days_before,
            'reminded_at' => $this->reminded_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'completion_note' => $this->completion_note,
        ];
    }
}

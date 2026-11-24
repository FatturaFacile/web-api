<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AgentCommissions\Domain\Enums\AccrualBasis;
use Modules\AgentCommissions\Domain\Enums\EntryStatus;
use Modules\AgentCommissions\Models\CommissionEntry;
use Modules\Payroll\Calculation\Dec;

/**
 * @mixin CommissionEntry
 */
final class CommissionEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $effective = Dec::sub((string) $this->commission_amount, (string) $this->credited_amount);
        $collected = $this->accrual_basis === AccrualBasis::Collected->value;
        $locked = in_array($this->status, [EntryStatus::PendingCollection->value, EntryStatus::AwaitingReconciliation->value, EntryStatus::PartiallyEligible->value], true);

        return [
            'id' => $this->id,
            'agent_profile_id' => $this->agent_profile_id,
            'agent_name' => $this->whenLoaded('agent', fn (): ?string => $this->agent?->representative?->name),
            'role' => $this->role,
            'invoice_id' => $this->invoice_id,
            'invoice_number' => $this->whenLoaded('invoice', fn (): ?string => $this->invoice?->invoice_number),
            'invoice_date' => $this->whenLoaded('invoice', fn (): ?string => $this->invoice?->date === null ? null : (string) $this->invoice->date->toDateString()),
            'rule_id' => $this->rule_id,
            'accrual_basis' => $this->accrual_basis,
            'currency' => $this->currency,
            'accrued_on' => $this->accrued_on->toDateString(),
            'base_amount' => (string) $this->base_amount,
            'rate' => $this->rate === null ? null : (string) $this->rate,
            'commission_amount' => (string) $this->commission_amount,
            'credited_amount' => (string) $this->credited_amount,
            'effective_amount' => Dec::round($effective),
            'eligible_amount' => (string) $this->eligible_amount,
            'settled_amount' => (string) $this->settled_amount,
            'payable_amount' => Dec::round(Dec::sub((string) $this->eligible_amount, (string) $this->settled_amount)),
            'blocked_amount' => $collected && $locked ? Dec::round(Dec::max('0', Dec::sub($effective, (string) $this->eligible_amount))) : '0.00',
            'collected_ratio' => (string) $this->collected_ratio,
            'reconciled_ratio' => (string) $this->reconciled_ratio,
            'status' => $this->status,
            'movements' => $this->whenLoaded('movements', fn (): array => $this->movements->map(static fn ($movement): array => [
                'kind' => $movement->kind,
                'amount' => (string) $movement->amount,
                'occurred_on' => $movement->occurred_on->toDateString(),
                'note' => $movement->note,
            ])->all()),
        ];
    }
}

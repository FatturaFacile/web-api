<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AgentCommissions\Models\Statement;

/**
 * @mixin Statement
 */
final class StatementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'agent_profile_id' => $this->agent_profile_id,
            'agent_name' => $this->whenLoaded('agent', fn (): ?string => $this->agent?->representative?->name),
            'agent_email' => $this->whenLoaded('agent', fn (): ?string => $this->agent?->representative?->email),
            'settlement_channel' => $this->whenLoaded('agent', fn (): ?string => $this->agent?->settlement_channel),
            'period_type' => $this->period_type,
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'status' => $this->status,
            'currency' => $this->currency,
            'base_total' => (string) $this->base_total,
            'own_commission_total' => (string) $this->own_commission_total,
            'override_commission_total' => (string) $this->override_commission_total,
            'clawback_total' => (string) $this->clawback_total,
            'gross_commission' => (string) $this->gross_commission,
            'enasarco_base' => (string) $this->enasarco_base,
            'enasarco_agent' => (string) $this->enasarco_agent,
            'enasarco_company' => (string) $this->enasarco_company,
            'firr_accrual' => (string) $this->firr_accrual,
            'withholding_base' => (string) $this->withholding_base,
            'withholding_amount' => (string) $this->withholding_amount,
            'vat_amount' => (string) $this->vat_amount,
            'net_payable' => (string) $this->net_payable,
            'blocked_amount' => (string) $this->blocked_amount,
            'massimale_reached' => (bool) $this->massimale_reached,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'self_billing_number' => $this->self_billing_number,
            'self_billing_date' => $this->self_billing_date?->toDateString(),
            'self_billing_payload' => $this->self_billing_payload,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'payment_reference' => $this->payment_reference,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'sent_to' => $this->sent_to,
            'sent_count' => (int) $this->sent_count,
            'lines' => $this->whenLoaded('lines', fn (): array => $this->lines->map(static fn ($line): array => [
                'id' => $line->id,
                'entry_id' => $line->entry_id,
                'invoice_id' => $line->invoice_id,
                'invoice_number' => $line->invoice_number,
                'invoice_date' => $line->invoice_date?->toDateString(),
                'customer_name' => $line->customer_name,
                'role' => $line->role,
                'accrual_basis' => $line->accrual_basis,
                'base_amount' => (string) $line->base_amount,
                'rate' => $line->rate === null ? null : (string) $line->rate,
                'commission_amount' => (string) $line->commission_amount,
            ])->all()),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Resources\RecurringContracts;

use App\Models\RecurringContracts\RecurringContract;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RecurringContract
 */
class RecurringContractResource extends JsonResource
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
            'tenant_id' => $this->tenant_id,
            'customer_id' => $this->customer_id,
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer?->id,
                'company_name' => $this->customer?->company_name,
                'email' => $this->customer?->email,
                'vat_number' => $this->customer?->vat_number,
                'country' => $this->customer?->country,
            ]),
            'contract_number' => $this->contract_number,
            'title' => $this->title,
            'contract_type' => $this->contract_type,
            'status' => $this->status,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'billing_frequency' => $this->billing_frequency,
            'custom_interval_days' => $this->custom_interval_days,
            'billing_timing' => $this->billing_timing,
            'next_billing_date' => $this->next_billing_date?->toDateString(),
            'last_billed_date' => $this->last_billed_date?->toDateString(),
            'auto_renew' => (bool) $this->auto_renew,
            'renewal_period_months' => $this->renewal_period_months,
            'notice_period_days' => $this->notice_period_days,
            'currency' => $this->currency,
            'payment_term_id' => $this->payment_term_id,
            'payment_method' => $this->payment_method,
            'invoice_auto_issue' => (bool) $this->invoice_auto_issue,
            'notes' => $this->notes,
            'cancellation_reason' => $this->cancellation_reason,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'subtotal' => $this->calculateSubtotal(),
            'total' => $this->calculateTotal(),
            'lines' => RecurringContractLineResource::collection($this->whenLoaded('lines')),
            'indexation_clause' => new ContractIndexationClauseResource($this->whenLoaded('indexationClause')),
            'indexation_logs' => ContractIndexationLogResource::collection($this->whenLoaded('indexationLogs')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

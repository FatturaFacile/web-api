<?php

declare(strict_types=1);

namespace App\Http\Resources\RecurringContracts;

use App\Models\RecurringContracts\RecurringBillingRunItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RecurringBillingRunItem
 */
class RecurringBillingRunItemResource extends JsonResource
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
            'run_id' => $this->run_id,
            'recurring_contract_id' => $this->recurring_contract_id,
            'contract_number' => $this->contract?->contract_number,
            'customer_name' => $this->contract?->customer?->company_name,
            'billing_period_start' => $this->billing_period_start?->toDateString(),
            'billing_period_end' => $this->billing_period_end?->toDateString(),
            'idempotency_key' => $this->idempotency_key,
            'invoice_id' => $this->invoice_id,
            'invoice_number' => $this->invoice?->invoice_number,
            'invoice_total' => $this->invoice ? (string) $this->invoice->total_gross : null,
            'status' => $this->status,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

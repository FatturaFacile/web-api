<?php

declare(strict_types=1);

namespace App\Http\Resources\RecurringContracts;

use App\Models\RecurringContracts\RecurringBillingRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RecurringBillingRun
 */
class RecurringBillingRunResource extends JsonResource
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
            'run_type' => $this->run_type,
            'target_date' => $this->target_date?->toDateString(),
            'status' => $this->status,
            'contracts_processed' => $this->contracts_processed,
            'invoices_generated' => $this->invoices_generated,
            'total_amount' => (string) $this->total_amount,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'items' => RecurringBillingRunItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

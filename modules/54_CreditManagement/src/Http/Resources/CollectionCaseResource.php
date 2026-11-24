<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CreditManagement\Models\CreditCollectionCase;

/**
 * A collection case with its invoices, strategy, actions, promises and disputes when loaded.
 *
 * @mixin CreditCollectionCase
 */
final class CollectionCaseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'case_number' => $this->case_number,
            'customer_id' => $this->customer_id,
            'customer_name' => $this->customer_name ?? null,
            'status' => $this->status,
            'priority' => $this->priority,
            'collector_id' => $this->collector_id,
            'strategy_id' => $this->strategy_id,
            'current_step_order' => $this->current_step_order,
            'outstanding_amount' => $this->outstanding_amount,
            'currency' => $this->currency,
            'opened_on' => $this->opened_on->toDateString(),
            'next_action_on' => $this->next_action_on?->toDateString(),
            'closed_on' => $this->closed_on?->toDateString(),
            'notes' => $this->notes,
            'strategy' => new DunningStrategyResource($this->whenLoaded('strategy')),
            'invoices' => CaseInvoiceResource::collection($this->whenLoaded('invoices')),
            'actions' => CaseActionResource::collection($this->whenLoaded('actions')),
            'promises' => PromiseToPayResource::collection($this->whenLoaded('promises')),
            'disputes' => DisputeResource::collection($this->whenLoaded('disputes')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

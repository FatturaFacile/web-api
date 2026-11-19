<?php

declare(strict_types=1);

namespace Modules\Controlling\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Controlling\Models\AnalyticalLedgerEntry;

/**
 * API representation of a AnalyticalLedgerEntry.
 *
 * @property AnalyticalLedgerEntry $resource
 */
final class LedgerEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'posting_date' => $this->resource->posting_date,
            'fiscal_year' => $this->resource->fiscal_year,
            'fiscal_month' => $this->resource->fiscal_month,
            'source_type' => $this->resource->source_type,
            'source_id' => $this->resource->source_id,
            'account_code' => $this->resource->account_code,
            'nature_type' => $this->resource->nature_type,
            'cost_center_id' => $this->resource->cost_center_id,
            'job_id' => $this->resource->job_id,
            'product_id' => $this->resource->product_id,
            'product_family_id' => $this->resource->product_family_id,
            'customer_id' => $this->resource->customer_id,
            'sales_agent_id' => $this->resource->sales_agent_id,
            'amount' => $this->resource->amount,
            'quantity' => $this->resource->quantity,
            'description' => $this->resource->description,
            'is_allocated' => $this->resource->is_allocated,
            'allocation_run_id' => $this->resource->allocation_run_id,
        ];
    }
}

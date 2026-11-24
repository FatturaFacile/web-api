<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CRM\Models\Opportunity;

/**
 * @mixin Opportunity
 */
final class OpportunityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'opportunity_number' => $this->opportunity_number,
            'name' => $this->name,
            'amount' => (float) $this->amount,
            'expected_revenue' => (float) $this->expected_revenue,
            'currency' => $this->currency,
            'probability' => (float) $this->probability,
            'forecast_category' => $this->forecast_category,
            'expected_close_date' => $this->expected_close_date?->toDateString(),
            'actual_close_date' => $this->actual_close_date?->toDateString(),
            'status' => $this->status,
            'win_loss_reason' => $this->win_loss_reason,
            'competitor' => $this->competitor,
            'discount_percentage' => (float) $this->discount_percentage,
            'approval_status' => $this->approval_status,
            'workflow_instance_id' => $this->workflow_instance_id,
            'account' => $this->account ? [
                'id' => $this->account->id,
                'name' => $this->account->name,
                'tier' => $this->account->tier,
            ] : null,
            'stage' => $this->stage ? [
                'id' => $this->stage->id,
                'code' => $this->stage->code,
                'name' => $this->stage->name,
                'is_won' => (bool) $this->stage->is_won,
                'is_lost' => (bool) $this->stage->is_lost,
            ] : null,
            'sales_quote_id' => $this->sales_quote_id,
            'project_id' => $this->project_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

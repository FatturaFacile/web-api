<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CRM\Models\Lead;

/**
 * @mixin Lead
 */
final class LeadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'company_name' => $this->company_name,
            'title' => $this->title,
            'email' => $this->email,
            'phone' => $this->phone,
            'website' => $this->website,
            'country' => $this->country,
            'status' => $this->status,
            'score' => $this->score,
            'rating' => $this->rating,
            'estimated_budget' => $this->estimated_budget,
            'lead_source' => $this->leadSource ? [
                'id' => $this->leadSource->id,
                'code' => $this->leadSource->code,
                'name' => $this->leadSource->name,
            ] : null,
            'converted_at' => $this->converted_at?->toIso8601String(),
            'converted_account_id' => $this->converted_account_id,
            'converted_contact_id' => $this->converted_contact_id,
            'converted_opportunity_id' => $this->converted_opportunity_id,
            'tags' => $this->tags,
            'assigned_user_id' => $this->assigned_user_id,
            'score_breakdown' => $this->score_breakdown,
            'scored_at' => $this->scored_at?->toIso8601String(),
            'vat_number' => $this->vat_number,
            'phone_e164' => $this->phone_e164,
            'industry' => $this->industry,
            'employee_count' => $this->employee_count,
            'annual_revenue_estimate' => $this->annual_revenue_estimate,
            'state_region' => $this->state_region,
            'product_line' => $this->product_line,
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),
            'merged_into_lead_id' => $this->merged_into_lead_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

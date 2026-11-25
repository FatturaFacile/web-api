<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\TenderManagement\Models\TenderNotice;

/**
 * @mixin TenderNotice
 */
final class NoticeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'jurisdiction' => $this->jurisdiction->value,
            'procedure_type' => $this->procedure_type->value,
            'contracting_authority_name' => $this->contracting_authority_name,
            'contracting_authority_contact_id' => $this->contracting_authority_contact_id,
            'simap_project_number' => $this->simap_project_number,
            'cig_code_it' => $this->cig_code_it,
            'cup_code_it' => $this->cup_code_it,
            'rup_name' => $this->rup_name,
            'dedicated_bank_account_it' => $this->dedicated_bank_account_it,
            'cpv_category_codes' => $this->cpv_category_codes ?? [],
            'award_criterion' => $this->award_criterion->value,
            'formula_type' => $this->formula_type?->value,
            'currency' => $this->currency,
            'base_auction_amount' => $this->base_auction_amount,
            'non_subject_safety_costs_amount' => $this->non_subject_safety_costs_amount,
            'estimated_labor_cost_amount' => $this->estimated_labor_cost_amount,
            'technical_max_score' => $this->technical_max_score,
            'economic_max_score' => $this->economic_max_score,
            'required_soa_categories' => $this->required_soa_categories ?? [],
            'required_min_turnover' => $this->required_min_turnover,
            'turnover_reference_years' => $this->turnover_reference_years,
            'required_similar_projects_count' => $this->required_similar_projects_count,
            'required_similar_projects_amount' => $this->required_similar_projects_amount,
            'required_certifications' => $this->required_certifications ?? [],
            'publication_date' => $this->publication_date?->toDateString(),
            'clarification_deadline_at' => $this->clarification_deadline_at?->toIso8601String(),
            'submission_peremptory_deadline_at' => $this->submission_peremptory_deadline_at?->toIso8601String(),
            'status' => $this->status->value,
            'participations_count' => $this->whenCounted('participations'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

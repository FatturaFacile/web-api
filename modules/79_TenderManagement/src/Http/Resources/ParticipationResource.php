<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\TenderManagement\Models\TenderParticipation;

/**
 * @mixin TenderParticipation
 */
final class ParticipationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $detailed = (bool) $request->attributes->get('tender_detail', false);
        $notice = $this->relationLoaded('notice') ? $this->notice : null;

        return [
            'id' => $this->id,
            'tender_id' => $this->tender_id,
            'participation_mode' => $this->participation_mode->value,
            'rti_quota_percentage' => $this->rti_quota_percentage,
            'partner_companies' => $this->partner_companies ?? [],
            'go_no_go_score' => $this->go_no_go_score,
            'provisional_bid_bond_id' => $this->provisional_bid_bond_id,
            'bid_bond_base_percentage' => $this->bid_bond_base_percentage,
            'bid_bond_applied_reductions' => $this->bid_bond_applied_reductions ?? [],
            'bid_bond_final_amount' => $this->bid_bond_final_amount,
            'anac_contribution_receipt_id' => $this->anac_contribution_receipt_id,
            'anac_contribution_amount' => $this->anac_contribution_amount,
            'administrative_envelope_docs' => $this->publicDocs('administrative'),
            'technical_envelope_docs' => $this->publicDocs('technical'),
            'technical_envelope_score_target' => $this->technical_envelope_score_target,
            'economic_envelope_docs' => $this->publicDocs('economic'),
            'economic_discount_percentage_offered' => $this->economic_discount_percentage_offered,
            'offered_amount' => $this->offered_amount,
            'estimated_total_cost' => $this->estimated_total_cost,
            'net_margin_amount' => $this->net_margin_amount,
            'company_safety_costs_declared' => $this->company_safety_costs_declared,
            'company_labor_costs_declared' => $this->company_labor_costs_declared,
            'offer_simulation' => $this->offer_simulation,
            'has_edgue' => $this->edgue_data !== null,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'status' => $this->status->value,
            'allowed_transitions' => array_map(static fn ($status): string => $status->value, $this->status->transitions()),
            'converted_contract_id' => $this->converted_contract_id,
            'converted_project_job_id' => $this->converted_project_job_id,
            'converted_at' => $this->converted_at?->toIso8601String(),
            'notes' => $this->notes,
            'notice' => $notice === null ? null : ($detailed ? (new NoticeResource($notice))->toArray($request) : [
                'id' => $notice->id,
                'title' => $notice->title,
                'jurisdiction' => $notice->jurisdiction->value,
                'contracting_authority_name' => $notice->contracting_authority_name,
                'base_auction_amount' => $notice->base_auction_amount,
                'currency' => $notice->currency,
                'submission_peremptory_deadline_at' => $notice->submission_peremptory_deadline_at?->toIso8601String(),
                'clarification_deadline_at' => $notice->clarification_deadline_at?->toIso8601String(),
                'cig_code_it' => $notice->cig_code_it,
                'simap_project_number' => $notice->simap_project_number,
                'award_criterion' => $notice->award_criterion->value,
                'formula_type' => $notice->formula_type?->value,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The checklist items of an envelope without their storage path.
     *
     * @return list<array<string, mixed>>
     */
    private function publicDocs(string $envelope): array
    {
        return array_map(static function (array $item): array {
            unset($item['path']);

            return $item;
        }, $this->envelopeDocs($envelope));
    }
}

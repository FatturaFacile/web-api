<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CustomerSatisfaction\Models\DetractorRecoveryCase;

/**
 * @mixin DetractorRecoveryCase
 */
final class RecoveryCaseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'survey_response_id' => $this->survey_response_id,
            'customer_id' => $this->customer_id,
            'customer_name' => $this->whenLoaded('customer', fn () => $this->customer?->company_name ?: trim((string) $this->customer?->first_name.' '.(string) $this->customer?->last_name)),
            'account_manager_name' => $this->whenLoaded('accountManager', fn () => $this->accountManager?->name),
            'support_lead_name' => $this->whenLoaded('supportLead', fn () => $this->supportLead?->name),
            'assigned_account_manager_id' => $this->assigned_account_manager_id,
            'assigned_support_lead_id' => $this->assigned_support_lead_id,
            'crm_task_id' => $this->crm_task_id,
            'priority' => $this->priority->value,
            'status' => $this->status->value,
            'next_statuses' => array_map(static fn ($status): string => $status->value, $this->status->next()),
            'sla_due_at' => $this->sla_due_at->toIso8601String(),
            'sla_breached' => $this->isSlaBreached(),
            'first_contacted_at' => $this->first_contacted_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'churn_risk_updated' => $this->churn_risk_updated,
            'previous_churn_risk' => $this->previous_churn_risk,
            'new_churn_risk' => $this->new_churn_risk,
            'resolution_summary' => $this->resolution_summary,
            'root_cause_code' => $this->root_cause_code,
            'response' => $this->whenLoaded('response', fn () => new SurveyResponseResource($this->response)),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

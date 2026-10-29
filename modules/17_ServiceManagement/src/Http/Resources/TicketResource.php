<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class TicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'ticket_number' => $this->ticket_number,
            'title' => $this->title,
            'description' => $this->description,
            'customer_id' => $this->customer_id,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'priority' => $this->priority,
            'status' => $this->status,
            'channel' => $this->channel,
            'service_category_id' => $this->service_category_id,
            'service_contract_id' => $this->service_contract_id,
            'sla_policy_id' => $this->sla_policy_id,
            'assigned_to_id' => $this->assigned_to_id,
            'service_team_id' => $this->service_team_id,
            'response_due_at' => $this->response_due_at?->toIso8601String(),
            'resolution_due_at' => $this->resolution_due_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'is_sla_breached' => (bool) $this->is_sla_breached,
            'is_escalated' => (bool) $this->is_escalated,
            'escalation_level' => (int) $this->escalation_level,
            'is_locked' => (bool) $this->is_locked,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

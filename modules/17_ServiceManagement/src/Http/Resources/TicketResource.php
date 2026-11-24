<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ServiceManagement\Services\Helpdesk\TicketSlaClock;

/**
 * A ticket with its stored columns (subject, source, category_id, assigned_technician_id, assigned_team_id, contact_id,
 * type). The earlier keys (title, channel, service_category_id, assigned_to_id, service_team_id) stay, read from the same
 * columns, because the published schema and clients use them. Contact e-mail, phone and SLA policy have no column of
 * their own and come from the metadata the ticket was created with.
 */
final class TicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];

        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'ticket_number' => $this->ticket_number,
            'type' => $this->type,
            'subject' => $this->subject,
            'title' => $this->subject,
            'description' => $this->description,
            'customer_id' => $this->customer_id,
            'contact_id' => $this->contact_id,
            'contact_email' => $metadata['contact_email'] ?? null,
            'contact_phone' => $metadata['contact_phone'] ?? null,
            'priority' => $this->priority,
            'status' => $this->status,
            'source' => $this->source,
            'channel' => $this->source,
            'category_id' => $this->category_id,
            'service_category_id' => $this->category_id,
            'service_contract_id' => $this->service_contract_id,
            'sla_policy_id' => $metadata['sla_policy_id'] ?? null,
            'assigned_technician_id' => $this->assigned_technician_id,
            'assigned_to_id' => $this->assigned_technician_id,
            'assigned_team_id' => $this->assigned_team_id,
            'service_team_id' => $this->assigned_team_id,
            'requester_user_id' => $this->requester_user_id,
            'asset_id' => $this->asset_id,
            'response_due_at' => $this->response_due_at?->toIso8601String(),
            'resolution_due_at' => $this->resolution_due_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'is_sla_breached' => (bool) $this->is_sla_breached,
            'is_escalated' => (bool) $this->is_escalated,
            'escalation_level' => (int) $this->escalation_level,
            'is_locked' => (bool) $this->is_locked,
            'impact' => $this->impact,
            'urgency' => $this->urgency,
            'tags' => $this->tags ?? [],
            'first_responded_at' => $this->first_responded_at?->toIso8601String(),
            'merged_into_ticket_id' => $this->merged_into_ticket_id,
            'split_from_ticket_id' => $this->split_from_ticket_id,
            'reopened_count' => (int) $this->reopened_count,
            'status_changed_at' => $this->status_changed_at?->toIso8601String(),
            'sla_paused_at' => $this->sla_paused_at?->toIso8601String(),
            'sla_state' => app(TicketSlaClock::class)->state($this->resource),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

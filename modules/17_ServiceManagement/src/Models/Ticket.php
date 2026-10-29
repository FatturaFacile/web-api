<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Workflow\Contracts\LockableByWorkflowInterface;
use Modules\Workflow\Models\WorkflowInstance;

final class Ticket extends BaseEnterpriseModel implements LockableByWorkflowInterface
{
    protected $table = 'esm_tickets';

    protected function casts(): array
    {
        return [
            'response_due_at' => 'datetime',
            'resolution_due_at' => 'datetime',
            'first_responded_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'is_escalated' => 'boolean',
            'escalation_level' => 'integer',
            'is_locked' => 'boolean',
            'csat_score' => 'integer',
            'custom_fields' => 'array',
            'metadata' => 'array',
        ];
    }

    public function lockByWorkflow(WorkflowInstance $instance): void
    {
        $this->update([
            'workflow_instance_id' => $instance->id,
            'approval_status' => 'pending_approval',
            'is_locked' => true,
        ]);
    }

    public function isLockedByWorkflow(): bool
    {
        return (bool) $this->is_locked;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_id');
    }

    public function serviceContract(): BelongsTo
    {
        return $this->belongsTo(ServiceContract::class, 'service_contract_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    public function assignedTeam(): BelongsTo
    {
        return $this->belongsTo(ServiceTeam::class, 'assigned_team_id');
    }

    public function assignedTechnician(): BelongsTo
    {
        return $this->belongsTo(ServiceTechnician::class, 'assigned_technician_id');
    }

    public function knowledgeArticle(): BelongsTo
    {
        return $this->belongsTo(KnowledgeArticle::class, 'knowledge_article_id');
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TicketTimeEntry::class, 'ticket_id');
    }

    public function incident(): HasOne
    {
        return $this->hasOne(Incident::class, 'ticket_id');
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'ticket_id');
    }

    public function csatSurveys(): HasMany
    {
        return $this->hasMany(CSATSurvey::class, 'ticket_id');
    }
}

<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
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

    protected function title(): Attribute
    {
        return Attribute::make(
            get: fn ($value, $attributes) => $attributes['subject'] ?? $value ?? '',
            set: fn ($value) => ['subject' => $value]
        );
    }

    protected function channel(): Attribute
    {
        return Attribute::make(
            get: fn ($value, $attributes) => $attributes['source'] ?? $value ?? 'portal',
            set: fn ($value) => ['source' => $value]
        );
    }

    protected function serviceCategoryId(): Attribute
    {
        return Attribute::make(
            get: fn ($value, $attributes) => $attributes['category_id'] ?? $value,
            set: fn ($value) => ['category_id' => $value]
        );
    }

    protected function assignedToId(): Attribute
    {
        return Attribute::make(
            get: fn ($value, $attributes) => $attributes['assigned_technician_id'] ?? $value,
            set: fn ($value) => ['assigned_technician_id' => $value]
        );
    }

    protected function serviceTeamId(): Attribute
    {
        return Attribute::make(
            get: fn ($value, $attributes) => $attributes['assigned_team_id'] ?? $value,
            set: fn ($value) => ['assigned_team_id' => $value]
        );
    }

    protected function resolutionNotes(): Attribute
    {
        return Attribute::make(
            get: fn ($value, $attributes) => $attributes['resolution_summary'] ?? $value,
            set: fn ($value) => ['resolution_summary' => $value]
        );
    }

    protected function isSlaBreached(): Attribute
    {
        return Attribute::make(
            get: function ($value, $attributes) {
                $meta = json_decode($attributes['metadata'] ?? '{}', true) ?: [];
                if (isset($meta['is_sla_breached'])) {
                    return (bool) $meta['is_sla_breached'];
                }
                if (! empty($attributes['resolution_due_at'])) {
                    $due = Carbon::parse($attributes['resolution_due_at']);

                    return $due->isPast() && ! in_array($attributes['status'] ?? '', ['resolved', 'closed'], true);
                }

                return false;
            },
            set: function ($value, $attributes) {
                $meta = json_decode($attributes['metadata'] ?? '{}', true) ?: [];
                $meta['is_sla_breached'] = (bool) $value;

                return ['metadata' => json_encode($meta)];
            }
        );
    }

    protected function actualResolutionMinutes(): Attribute
    {
        return Attribute::make(
            get: function ($value, $attributes) {
                $meta = json_decode($attributes['metadata'] ?? '{}', true) ?: [];

                return (int) ($meta['actual_resolution_minutes'] ?? 0);
            },
            set: function ($value, $attributes) {
                $meta = json_decode($attributes['metadata'] ?? '{}', true) ?: [];
                $meta['actual_resolution_minutes'] = (int) $value;

                return ['metadata' => json_encode($meta)];
            }
        );
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

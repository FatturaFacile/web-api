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
use Modules\ServiceManagement\Enums\TicketStatus;
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
            'tags' => 'array',
            'reopened_count' => 'integer',
            'status_changed_at' => 'datetime',
            'sla_paused_at' => 'datetime',
            'sla_paused_minutes' => 'integer',
            'customer_last_read_at' => 'datetime',
            'response_warning_sent_at' => 'datetime',
            'resolution_warning_sent_at' => 'datetime',
            'response_breached_at' => 'datetime',
            'resolution_breached_at' => 'datetime',
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

    /**
     * @return HasMany<TicketWatcher, $this>
     */
    public function watchers(): HasMany
    {
        return $this->hasMany(TicketWatcher::class, 'ticket_id');
    }

    /**
     * @return HasMany<TicketActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(TicketActivity::class, 'ticket_id');
    }

    /**
     * @return HasMany<TicketMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class, 'ticket_id');
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_ticket_id');
    }

    /**
     * @return HasMany<Ticket, $this>
     */
    public function mergedTickets(): HasMany
    {
        return $this->hasMany(self::class, 'merged_into_ticket_id');
    }

    /**
     * The address replies to the requester go to: the one the ticket was opened with, else the customer's.
     */
    public function requesterEmail(): ?string
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $email = $metadata['contact_email'] ?? $this->customer?->email;

        return is_string($email) && $email !== '' ? $email : null;
    }

    /**
     * The requester's name for greetings and placeholders.
     */
    public function requesterName(): string
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $contact = $this->customer;
        $contactName = $contact === null ? '' : trim((string) ($contact->company_name ?: trim(($contact->first_name ?? '').' '.($contact->last_name ?? ''))));

        return (string) ($metadata['contact_name'] ?? ($contactName !== '' ? $contactName : ($this->requesterEmail() ?? 'Customer')));
    }

    public function statusEnum(): TicketStatus
    {
        return TicketStatus::fromStored($this->status);
    }
}

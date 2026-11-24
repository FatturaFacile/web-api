<?php

declare(strict_types=1);

namespace Modules\CRM\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A marketing automation sequence: ordered steps, the trigger that enrols subjects and the conditions that make an enrolment exit early.
 */
final class AutomationSequence extends BaseEnterpriseModel
{
    public const array Triggers = ['manual', 'lead_created', 'lead_qualified', 'contact_created'];

    public const array ExitConditions = ['lead_converted', 'lead_disqualified', 'consent_withdrawn'];

    protected $table = 'crm_automation_sequences';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'exit_conditions' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Segment, $this>
     */
    public function segment(): BelongsTo
    {
        return $this->belongsTo(Segment::class, 'segment_id');
    }

    /**
     * @return HasMany<AutomationStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(AutomationStep::class, 'sequence_id');
    }

    /**
     * @return HasMany<AutomationEnrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(AutomationEnrollment::class, 'sequence_id');
    }
}

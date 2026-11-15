<?php

declare(strict_types=1);

namespace Modules\CRM\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A contact or lead enrolled in a sequence, with the position reached and the next execution time.
 */
final class AutomationEnrollment extends BaseEnterpriseModel
{
    public const array Statuses = ['active', 'completed', 'exited'];

    protected $table = 'crm_automation_enrollments';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'current_position' => 'integer',
            'next_run_at' => 'datetime',
            'enrolled_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AutomationSequence, $this>
     */
    public function sequence(): BelongsTo
    {
        return $this->belongsTo(AutomationSequence::class, 'sequence_id');
    }

    /**
     * @return HasMany<AutomationStepLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(AutomationStepLog::class, 'enrollment_id');
    }
}

<?php

declare(strict_types=1);

namespace Modules\CRM\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of a sequence: an email, a task for the owner, or a pure wait; delay_days is counted from the previous step.
 */
final class AutomationStep extends BaseEnterpriseModel
{
    public const array Types = ['email', 'task', 'wait'];

    protected $table = 'crm_automation_steps';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'delay_days' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<AutomationSequence, $this>
     */
    public function sequence(): BelongsTo
    {
        return $this->belongsTo(AutomationSequence::class, 'sequence_id');
    }
}

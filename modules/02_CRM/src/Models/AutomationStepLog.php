<?php

declare(strict_types=1);

namespace Modules\CRM\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Execution log of one step of an enrollment (sent, skipped or failed).
 */
final class AutomationStepLog extends BaseEnterpriseModel
{
    protected $table = 'crm_automation_step_logs';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'executed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AutomationEnrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(AutomationEnrollment::class, 'enrollment_id');
    }
}

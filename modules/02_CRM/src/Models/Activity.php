<?php

declare(strict_types=1);

namespace Modules\CRM\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A call, meeting, task, note, email or follow-up logged on a CRM record, with assignee, due date and reminder.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $subject
 * @property string $type
 * @property string $status
 * @property string|null $related_type
 * @property string|null $related_id
 * @property string|null $assigned_user_id
 * @property Carbon|null $due_date
 * @property Carbon|null $completed_at
 * @property Carbon|null $reminder_at
 * @property Carbon|null $reminder_sent_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Activity extends BaseEnterpriseModel
{
    protected $table = 'crm_activities';

    protected function casts(): array
    {
        return [
            'due_date' => 'datetime',
            'completed_at' => 'datetime',
            'reminder_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'duration_minutes' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function related(): MorphTo
    {
        return $this->morphTo(null, 'related_type', 'related_id');
    }
}

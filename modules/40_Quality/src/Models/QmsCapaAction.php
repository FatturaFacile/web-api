<?php

declare(strict_types=1);

namespace Modules\Quality\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A corrective or preventive action (CAPA) with an owner and a due date, optionally tied to a non-conformity. After it
 * is completed its effectiveness is checked: effective closes it as verified, not effective leaves it ineffective and
 * blocks the closure of the non-conformity.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $nonconformity_id
 * @property string $number
 * @property string $type
 * @property string $title
 * @property string|null $description
 * @property string $owner_id
 * @property Carbon $due_date
 * @property string $status
 * @property Carbon|null $completed_at
 * @property string|null $completed_by
 * @property Carbon|null $effectiveness_check_due_date
 * @property Carbon|null $effectiveness_checked_at
 * @property string|null $effectiveness_checked_by
 * @property string|null $effectiveness_result
 * @property string|null $effectiveness_notes
 */
final class QmsCapaAction extends BaseEnterpriseModel
{
    public const array Types = ['corrective', 'preventive'];

    public const array OpenStatuses = ['open', 'in_progress'];

    /** Statuses that do not block the closure of the non-conformity. */
    public const array SettledStatuses = ['verified', 'cancelled'];

    protected $table = 'qms_capa_actions';

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'completed_at' => 'datetime',
            'effectiveness_check_due_date' => 'date',
            'effectiveness_checked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<QmsNonconformity, $this>
     */
    public function nonconformity(): BelongsTo
    {
        return $this->belongsTo(QmsNonconformity::class, 'nonconformity_id');
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, self::OpenStatuses, true) && $this->due_date->endOfDay()->isPast();
    }
}

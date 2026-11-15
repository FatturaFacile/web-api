<?php

declare(strict_types=1);

namespace Modules\Hospitality\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cleaning or inspection task of an accommodation. Completing it makes the room clean (or inspected).
 *
 * @property string $id
 * @property string $accommodation_id
 * @property string $task_type
 * @property string $status
 * @property string|null $assigned_to
 */
final class HousekeepingTask extends HospitalityRecord
{
    public const array Types = ['checkout_clean', 'daily', 'inspection'];

    public const array Statuses = ['pending', 'in_progress', 'done'];

    protected $table = 'hosp_housekeeping_tasks';

    protected function casts(): array
    {
        return ['due_on' => 'date:Y-m-d', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Accommodation, $this>
     */
    public function accommodation(): BelongsTo
    {
        return $this->belongsTo(Accommodation::class, 'accommodation_id');
    }
}

<?php

declare(strict_types=1);

namespace Modules\Workshop\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A planned window of work on a folder for a technician and a bay. Planned, in progress, done or cancelled; planned and
 * in-progress slots hold the technician and the bay for their window.
 *
 * @property string $id
 * @property string $folder_id
 * @property string|null $technician_user_id
 * @property string|null $bay_id
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property string $status
 * @property string|null $notes
 */
final class WorkshopJobSlot extends WorkshopRecord
{
    /** @var array<string, list<string>> */
    public const array Transitions = [
        'planned' => ['in_progress', 'cancelled'],
        'in_progress' => ['done', 'cancelled'],
        'done' => [],
        'cancelled' => [],
    ];

    /** Statuses that hold the technician and the bay. */
    public const array Holding = ['planned', 'in_progress'];

    protected $table = 'workshop_job_slots';

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<WorkshopFolder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(WorkshopFolder::class, 'folder_id');
    }

    /**
     * @return BelongsTo<WorkshopBay, $this>
     */
    public function bay(): BelongsTo
    {
        return $this->belongsTo(WorkshopBay::class, 'bay_id');
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TimeEntryCorrectionStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimeEntryCorrection extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'time_entry_id', 'requested_by', 'reviewed_by', 'status',
        'reason', 'original_values', 'proposed_values', 'review_reason', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TimeEntryCorrectionStatus::class,
            'original_values' => 'array',
            'proposed_values' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<TimeEntry, $this> */
    public function timeEntry(): BelongsTo
    {
        return $this->belongsTo(TimeEntry::class);
    }
}

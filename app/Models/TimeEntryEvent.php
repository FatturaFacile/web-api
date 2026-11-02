<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TimeEntryEventType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimeEntryEvent extends Model
{
    use BelongsToTenant, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'time_entry_id',
        'sequence',
        'type',
        'occurred_at',
        'recorded_at',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'type' => TimeEntryEventType::class,
            'occurred_at' => 'datetime',
            'recorded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<TimeEntry, $this> */
    public function timeEntry(): BelongsTo
    {
        return $this->belongsTo(TimeEntry::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}

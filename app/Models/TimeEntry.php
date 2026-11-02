<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TimeEntry extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'clock_in',
        'clock_out',
        'clock_in_lat',
        'clock_in_lng',
        'is_simplified_record',
        'break_minutes',
        'approved_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'clock_in' => 'datetime',
            'clock_out' => 'datetime',
            'clock_in_lat' => 'decimal:7',
            'clock_in_lng' => 'decimal:7',
            'is_simplified_record' => 'boolean',
            'break_minutes' => 'integer',
            'corrected_clock_in' => 'datetime',
            'corrected_clock_out' => 'datetime',
            'corrected_break_minutes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return HasMany<TimeEntryEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(TimeEntryEvent::class)->orderBy('sequence');
    }

    /** @return HasMany<TimeEntryCorrection, $this> */
    public function corrections(): HasMany
    {
        return $this->hasMany(TimeEntryCorrection::class);
    }

    public function effectiveClockIn(): Carbon
    {
        return $this->corrected_clock_in ?? $this->clock_in;
    }

    public function effectiveClockOut(): ?Carbon
    {
        return $this->corrected_clock_out ?? $this->clock_out;
    }

    public function effectiveBreakMinutes(): int
    {
        return (int) ($this->corrected_break_minutes ?? $this->break_minutes);
    }

    /**
     * @param  Builder<TimeEntry>  $query
     * @return Builder<TimeEntry>
     */
    public function scopeOpenAttendance(Builder $query): Builder
    {
        return $query->whereNull('clock_out')->whereNull('corrected_clock_out');
    }

    /**
     * @param  Builder<TimeEntry>  $query
     * @return Builder<TimeEntry>
     */
    public function scopeEffectivePeriod(Builder $query, Carbon $start, Carbon $end): Builder
    {
        return $query->whereRaw('COALESCE(corrected_clock_in, clock_in) >= ?', [$start])
            ->whereRaw('COALESCE(corrected_clock_in, clock_in) < ?', [$end]);
    }
}

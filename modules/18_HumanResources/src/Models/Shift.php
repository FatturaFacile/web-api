<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Carbon\CarbonImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\HumanResources\Casts\UtcInstant;

/**
 * A shift of the plan. Only published shifts reach the employees, the time tracking and the absence plan.
 *
 * @property CarbonImmutable|null $starts_at
 * @property CarbonImmutable|null $ends_at
 */
final class Shift extends BaseEnterpriseModel
{
    public const string StatusDraft = 'draft';

    public const string StatusPublished = 'published';

    public const string SourceManual = 'manual';

    public const string SourceSequence = 'sequence';

    public const string SourceException = 'exception';

    protected $table = 'hr_shifts';

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d',
            'starts_at' => UtcInstant::class,
            'ends_at' => UtcInstant::class,
            'break_minutes' => 'integer',
            'planned_minutes' => 'integer',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeBetweenDays(Builder $query, string $from, string $to): Builder
    {
        return $query->whereDate('work_date', '>=', $from)->whereDate('work_date', '<=', $to);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::StatusPublished);
    }

    /**
     * @return BelongsTo<ShiftType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(ShiftType::class, 'shift_type_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}

<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\SkiOutdoorSchool\Domain\Enums\CourseStatus;
use Modules\SkiOutdoorSchool\Domain\Enums\Discipline;
use Modules\SkiOutdoorSchool\Domain\Enums\VatRegime;

/**
 * Weekly group course of one technical level (a class): the unit students are enrolled in and moved between after the Monday evaluation.
 *
 * @property string $code
 * @property Discipline $discipline
 * @property string $level_id
 * @property string|null $instructor_id
 * @property string $language_code
 * @property Carbon $week_start
 * @property Carbon $end_date
 * @property string $daily_start_time
 * @property int $duration_minutes
 * @property int $capacity
 * @property int $min_participants
 * @property string|null $meeting_point
 * @property string $price_net
 * @property VatRegime $vat_regime
 * @property CourseStatus $status
 * @property int|null $enrolled_count
 */
final class GroupCourse extends BaseEnterpriseModel
{
    protected $table = 'group_course_classes';

    protected function casts(): array
    {
        return [
            'discipline' => Discipline::class,
            'week_start' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'duration_minutes' => 'integer',
            'capacity' => 'integer',
            'min_participants' => 'integer',
            'price_net' => 'decimal:2',
            'vat_regime' => VatRegime::class,
            'status' => CourseStatus::class,
        ];
    }

    /**
     * @return BelongsTo<CourseLevel, $this>
     */
    public function level(): BelongsTo
    {
        return $this->belongsTo(CourseLevel::class, 'level_id');
    }

    /**
     * @return BelongsTo<Instructor, $this>
     */
    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class, 'instructor_id');
    }

    /**
     * @return HasMany<CourseEnrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(CourseEnrollment::class, 'class_id');
    }

    /** Enrolled students (withdrawn ones do not count against the capacity). */
    public function activeEnrollmentCount(): int
    {
        return $this->enrollments()->where('status', 'ENROLLED')->count();
    }
}

<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\SkiOutdoorSchool\Domain\Enums\AssignmentMethod;
use Modules\SkiOutdoorSchool\Domain\Enums\BookingSource;
use Modules\SkiOutdoorSchool\Domain\Enums\Discipline;
use Modules\SkiOutdoorSchool\Domain\Enums\LessonStatus;
use Modules\SkiOutdoorSchool\Domain\Enums\VatRegime;

/**
 * Private lesson booking: slot, discipline, language, nominated or rotated instructor, fiscal regime and status.
 *
 * @property string $reference
 * @property string|null $instructor_id
 * @property string|null $requested_instructor_id
 * @property bool $is_nominated
 * @property AssignmentMethod $assignment_method
 * @property Discipline $discipline
 * @property string $language_code
 * @property string|null $level_id
 * @property Carbon $lesson_date
 * @property string $start_time
 * @property string $end_time
 * @property int $slot_minutes
 * @property int $slot_count
 * @property int $duration_minutes
 * @property int $participants
 * @property string|null $customer_contact_id
 * @property string $customer_name
 * @property string|null $customer_email
 * @property string|null $customer_phone
 * @property string|null $meeting_point
 * @property string $price_net
 * @property VatRegime $vat_regime
 * @property LessonStatus $status
 * @property Carbon|null $completed_at
 * @property BookingSource $source
 * @property string|null $invoice_id
 * @property string|null $addons_invoice_id
 */
final class SkiLesson extends BaseEnterpriseModel
{
    protected $table = 'ski_lessons';

    protected function casts(): array
    {
        return [
            'is_nominated' => 'boolean',
            'assignment_method' => AssignmentMethod::class,
            'discipline' => Discipline::class,
            'lesson_date' => 'date:Y-m-d',
            'slot_minutes' => 'integer',
            'slot_count' => 'integer',
            'duration_minutes' => 'integer',
            'participants' => 'integer',
            'price_net' => 'decimal:2',
            'vat_regime' => VatRegime::class,
            'status' => LessonStatus::class,
            'source' => BookingSource::class,
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Instructor, $this>
     */
    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class, 'instructor_id');
    }

    /**
     * @return BelongsTo<Instructor, $this>
     */
    public function requestedInstructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class, 'requested_instructor_id');
    }

    /**
     * @return BelongsTo<CourseLevel, $this>
     */
    public function level(): BelongsTo
    {
        return $this->belongsTo(CourseLevel::class, 'level_id');
    }

    /**
     * @return HasMany<PackageItem, $this>
     */
    public function packageItems(): HasMany
    {
        return $this->hasMany(PackageItem::class, 'lesson_id');
    }

    public function hours(): float
    {
        return round($this->duration_minutes / 60, 2);
    }
}

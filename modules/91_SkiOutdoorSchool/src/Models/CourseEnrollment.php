<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\SkiOutdoorSchool\Domain\Enums\BookingSource;
use Modules\SkiOutdoorSchool\Domain\Enums\EnrollmentStatus;

/**
 * Enrolment of a student in a group course. The row follows the student when the student is moved to another level group, so the attendance history is kept.
 *
 * @property string $class_id
 * @property string|null $initial_class_id
 * @property string|null $contact_id
 * @property string $student_name
 * @property Carbon|null $birth_date
 * @property string|null $guardian_name
 * @property string|null $guardian_email
 * @property string|null $emergency_contact_name
 * @property string|null $emergency_contact_phone
 * @property int|null $bib_number
 * @property string $price_net
 * @property EnrollmentStatus $status
 * @property BookingSource $source
 * @property string|null $booking_reference
 * @property string|null $invoice_id
 * @property string|null $addons_invoice_id
 */
final class CourseEnrollment extends BaseEnterpriseModel
{
    protected $table = 'group_course_enrollments';

    protected function casts(): array
    {
        return [
            'birth_date' => 'date:Y-m-d',
            'bib_number' => 'integer',
            'price_net' => 'decimal:2',
            'status' => EnrollmentStatus::class,
            'source' => BookingSource::class,
        ];
    }

    /**
     * @return BelongsTo<GroupCourse, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(GroupCourse::class, 'class_id');
    }

    /**
     * @return HasMany<PackageItem, $this>
     */
    public function packageItems(): HasMany
    {
        return $this->hasMany(PackageItem::class, 'enrollment_id');
    }

    /**
     * @return HasMany<CourseAttendance, $this>
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(CourseAttendance::class, 'enrollment_id');
    }

    /**
     * @return HasMany<CourseTransfer, $this>
     */
    public function transfers(): HasMany
    {
        return $this->hasMany(CourseTransfer::class, 'enrollment_id');
    }
}

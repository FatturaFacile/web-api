<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Associations\Enums\CertificateKind;
use Modules\Associations\Enums\CourseEnrolmentStatus;
use Modules\Associations\Enums\CourseKind;
use Modules\GeneralLedger\Models\CostCenter;

/**
 * A recurring course or an activity with a date: one-off events and big events with tickets are courses too.
 *
 * @property string $id
 * @property CourseKind $kind
 * @property ?int $capacity
 * @property bool $registrations_open
 */
final class Course extends AssociationRecord
{
    public const string Active = 'active';

    public const string Cancelled = 'cancelled';

    public const string Ended = 'ended';

    protected $table = 'assoc_courses';

    protected function casts(): array
    {
        return [
            'kind' => CourseKind::class,
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'capacity' => 'integer',
            'waitlist_enabled' => 'boolean',
            'auto_close_when_full' => 'boolean',
            'registrations_open' => 'boolean',
            'required_certificate' => CertificateKind::class,
        ];
    }

    public function discipline(): BelongsTo
    {
        return $this->belongsTo(Discipline::class, 'discipline_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(BookableResource::class, 'resource_id');
    }

    public function accessArea(): BelongsTo
    {
        return $this->belongsTo(AccessArea::class, 'access_area_id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'cost_center_id');
    }

    public function feePlan(): BelongsTo
    {
        return $this->belongsTo(FeePlan::class, 'fee_plan_id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(CourseSchedule::class, 'course_id');
    }

    public function suspensions(): HasMany
    {
        return $this->hasMany(CourseSuspension::class, 'course_id');
    }

    public function instructors(): HasMany
    {
        return $this->hasMany(CourseInstructor::class, 'course_id');
    }

    public function enrolments(): HasMany
    {
        return $this->hasMany(CourseEnrolment::class, 'course_id');
    }

    public function ticketTypes(): HasMany
    {
        return $this->hasMany(TicketType::class, 'course_id');
    }

    public function enrolledCount(): int
    {
        return $this->enrolments()->where('status', CourseEnrolmentStatus::Enrolled)->count();
    }
}

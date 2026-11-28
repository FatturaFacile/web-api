<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Application\Queries;

use Modules\SkiOutdoorSchool\Application\Services\InstructorScheduleService;
use Modules\SkiOutdoorSchool\Application\Services\SchoolSettingsService;
use Modules\SkiOutdoorSchool\Domain\Enums\AvailabilityKind;
use Modules\SkiOutdoorSchool\Domain\Enums\BookingSource;
use Modules\SkiOutdoorSchool\Domain\Enums\CollaborationRegime;
use Modules\SkiOutdoorSchool\Domain\Enums\CourseStatus;
use Modules\SkiOutdoorSchool\Domain\Enums\Discipline;
use Modules\SkiOutdoorSchool\Domain\Enums\EnrollmentStatus;
use Modules\SkiOutdoorSchool\Domain\Enums\LessonStatus;
use Modules\SkiOutdoorSchool\Domain\Enums\LicenseType;
use Modules\SkiOutdoorSchool\Domain\Enums\PackageItemStatus;
use Modules\SkiOutdoorSchool\Domain\Enums\PackageItemType;
use Modules\SkiOutdoorSchool\Domain\Enums\PayoutStatus;
use Modules\SkiOutdoorSchool\Domain\Enums\Proficiency;
use Modules\SkiOutdoorSchool\Domain\Enums\RetentionMode;
use Modules\SkiOutdoorSchool\Domain\Enums\VatRegime;
use Modules\SkiOutdoorSchool\Domain\Services\PackagePricingService;
use Modules\SkiOutdoorSchool\Http\Resources\LevelResource;
use Modules\SkiOutdoorSchool\Models\CourseEnrollment;
use Modules\SkiOutdoorSchool\Models\CourseLevel;
use Modules\SkiOutdoorSchool\Models\GroupCourse;
use Modules\SkiOutdoorSchool\Models\Instructor;
use Modules\SkiOutdoorSchool\Models\InstructorCommission;
use Modules\SkiOutdoorSchool\Models\InstructorLanguage;
use Modules\SkiOutdoorSchool\Models\PayoutStatement;
use Modules\SkiOutdoorSchool\Models\SkiLesson;

/**
 * Reference catalogues (codes only: the labels come from the translation dictionary of the API) and the figures of the school dashboard.
 */
final class OverviewQueries
{
    private const array BASE_LANGUAGES = ['IT', 'DE', 'FR', 'EN'];

    public function __construct(
        private readonly SchoolSettingsService $settings,
        private readonly InstructorScheduleService $schedule,
        private readonly PackagePricingService $pricing,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function reference(): array
    {
        $settings = $this->settings->current();
        $extra = InstructorLanguage::query()->distinct()->pluck('language_code')->map(static fn (mixed $code): string => strtoupper((string) $code))->all();
        $values = static fn (string $enum): array => $enum::values();

        return [
            'disciplines' => $values(Discipline::class),
            'languages' => array_values(array_unique([...self::BASE_LANGUAGES, ...$extra])),
            'license_types' => $values(LicenseType::class),
            'retention_modes' => $values(RetentionMode::class),
            'collaboration_regimes' => $values(CollaborationRegime::class),
            'lesson_statuses' => $values(LessonStatus::class),
            'course_statuses' => $values(CourseStatus::class),
            'enrollment_statuses' => $values(EnrollmentStatus::class),
            'payout_statuses' => $values(PayoutStatus::class),
            'availability_kinds' => $values(AvailabilityKind::class),
            'proficiencies' => $values(Proficiency::class),
            'booking_sources' => $values(BookingSource::class),
            'package_item_statuses' => $values(PackageItemStatus::class),
            'slot_minutes' => array_map('intval', (array) config('ski_school.slot_minutes')),
            'max_slots_per_lesson' => (int) config('ski_school.max_slots_per_lesson'),
            'board' => (array) config('ski_school.board'),
            'levels' => LevelResource::collection(CourseLevel::query()->where('is_active', true)->orderBy('system')->orderBy('sort_order')->get())->resolve(),
            'package_item_types' => array_map(fn (PackageItemType $type): array => [
                'code' => $type->value,
                'category' => $type->vatCategory(),
                'default_vat_rate' => $this->pricing->addOnRate($settings->jurisdiction, $type),
            ], PackageItemType::cases()),
            'vat_regimes' => array_map(static fn (VatRegime $regime): array => [
                'code' => $regime->value,
                'jurisdiction' => $regime->jurisdiction(),
                'exempt' => $regime->isExempt(),
                'legal_reference' => $regime->legalReference(),
                'sdi_nature' => $regime->sdiNature(),
            ], VatRegime::cases()),
            'settings' => [
                'public_slug' => $settings->public_slug,
                'booking_enabled' => $settings->booking_enabled,
                'jurisdiction' => $settings->jurisdiction,
                'default_retention_mode' => $settings->default_retention_mode,
                'default_retention_value' => $settings->default_retention_value,
                'meeting_point' => $settings->meeting_point,
                'default_vat_regime' => VatRegime::exemptionFor($settings->jurisdiction)->value,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        $today = now()->toDateString();
        [$from, $to] = $this->schedule->period($today);
        $month = now()->format('Y-m');
        $open = [LessonStatus::REQUESTED->value, LessonStatus::CONFIRMED->value];

        return [
            'lessons_today' => SkiLesson::query()->whereDate('lesson_date', $today)->whereIn('status', [...$open, LessonStatus::COMPLETED->value])->count(),
            'unassigned_lessons' => SkiLesson::query()->whereNull('instructor_id')->where('status', LessonStatus::REQUESTED->value)->whereDate('lesson_date', '>=', $today)->count(),
            'lessons_week' => SkiLesson::query()->whereDate('lesson_date', '>=', $from)->whereDate('lesson_date', '<=', $to)->whereIn('status', [...$open, LessonStatus::COMPLETED->value])->count(),
            'lessons_to_invoice' => SkiLesson::query()->whereNull('invoice_id')->whereIn('status', [LessonStatus::CONFIRMED->value, LessonStatus::COMPLETED->value])->count(),
            'active_instructors' => Instructor::query()->where('is_active', true)->count(),
            'running_courses' => GroupCourse::query()->whereIn('status', [CourseStatus::OPEN->value, CourseStatus::RUNNING->value])->whereDate('end_date', '>=', $today)->count(),
            'enrolled_students' => CourseEnrollment::query()->where('status', EnrollmentStatus::ENROLLED->value)->whereHas('course', static fn ($q) => $q->whereDate('end_date', '>=', $today)->where('status', '!=', CourseStatus::CANCELLED->value))->count(),
            'enrollments_to_invoice' => CourseEnrollment::query()->whereNull('invoice_id')->where('status', EnrollmentStatus::ENROLLED->value)->count(),
            'expiring_licenses' => Instructor::query()->where('is_active', true)->whereNotNull('license_valid_until')->whereDate('license_valid_until', '<=', now()->addDays(60)->toDateString())->count(),
            'month' => $month,
            'month_net_payable' => round((float) InstructorCommission::query()->whereDate('work_date', '>=', $month.'-01')->whereDate('work_date', '<=', now()->endOfMonth()->toDateString())->sum('net_fee'), 2),
            'draft_statements' => PayoutStatement::query()->where('status', PayoutStatus::DRAFT->value)->count(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Application\Handlers;

use Modules\SkiOutdoorSchool\Application\Commands\BookLessonCommand;
use Modules\SkiOutdoorSchool\Application\Commands\EnrollStudentCommand;
use Modules\SkiOutdoorSchool\Application\Services\InstructorScheduleService;
use Modules\SkiOutdoorSchool\Application\Services\SchoolSettingsService;
use Modules\SkiOutdoorSchool\Domain\Enums\CourseStatus;
use Modules\SkiOutdoorSchool\Domain\Enums\Discipline;
use Modules\SkiOutdoorSchool\Domain\Enums\EnrollmentStatus;
use Modules\SkiOutdoorSchool\Domain\Enums\PackageItemType;
use Modules\SkiOutdoorSchool\Domain\Enums\VatRegime;
use Modules\SkiOutdoorSchool\Domain\Exceptions\SkiSchoolException;
use Modules\SkiOutdoorSchool\Domain\Services\PackagePricingService;
use Modules\SkiOutdoorSchool\Domain\ValueObjects\TimeRange;
use Modules\SkiOutdoorSchool\Models\CourseLevel;
use Modules\SkiOutdoorSchool\Models\GroupCourse;
use Modules\SkiOutdoorSchool\Models\Instructor;
use Modules\SkiOutdoorSchool\Models\InstructorLanguage;
use Modules\SkiOutdoorSchool\Models\PackageItem;

/**
 * The public, tenant-scoped booking engine: tourists look up free slots and weekly courses and book a private lesson or a course place with
 * optional rental / skipass add-ons. The price of the lesson, of the course and of every add-on is always computed here (the portal never
 * accepts a price), the instructor is chosen by the fair rotation unless the tourist asks for one, and only the first name of the instructor
 * and the booking reference are returned.
 */
final class PublicBookingHandler
{
    public function __construct(
        private readonly LessonHandler $lessons,
        private readonly GroupCourseHandler $courses,
        private readonly InstructorScheduleService $schedule,
        private readonly SchoolSettingsService $settings,
        private readonly PackagePricingService $pricing,
    ) {}

    /**
     * What the portal needs to render the first step: catalogue codes, levels, slot lengths, add-on prices and the VAT note.
     *
     * @return array<string, mixed>
     */
    public function catalogue(): array
    {
        $settings = $this->settings->current();
        $regime = $this->settings->vatRegime();

        return [
            'disciplines' => Discipline::values(),
            'languages' => array_values(array_unique(['IT', 'DE', 'FR', 'EN', ...array_map(static fn (mixed $code): string => strtoupper((string) $code), InstructorLanguage::query()->distinct()->pluck('language_code')->all())])),
            'slot_minutes' => array_map('intval', (array) config('ski_school.slot_minutes')),
            'max_slots_per_lesson' => (int) config('ski_school.max_slots_per_lesson'),
            'levels' => CourseLevel::query()->where('is_active', true)->orderBy('system')->orderBy('sort_order')->get()->map(static fn (CourseLevel $level): array => ['id' => (string) $level->id, 'system' => $level->system->value, 'code' => $level->code, 'label_key' => $level->labelKey(), 'color' => $level->color])->all(),
            'addons' => array_map(fn (PackageItemType $type): array => [
                'item_type' => $type->value,
                'unit_price_net' => $this->addonPrice($type),
                'vat_rate' => $this->pricing->addOnRate($settings->jurisdiction, $type),
            ], PackageItemType::cases()),
            'lesson_hourly_price' => (float) config('ski_school.lesson_hourly_price'),
            'currency' => $settings->jurisdiction === 'CH' ? 'CHF' : 'EUR',
            'vat_regime' => $regime->value,
            'vat_legal_reference' => $regime->legalReference(),
            'meeting_point' => $settings->meeting_point,
        ];
    }

    /**
     * Free start times of a day with how many instructors could teach, and the instructors the tourist may request (first name and initial).
     *
     * @param  array<string, mixed>  $params  date, discipline, language, slot_minutes, slot_count
     * @return array{date: string, slots: list<array{start: string, end: string, available_instructors: int}>, instructors: list<array<string, mixed>>}
     */
    public function availability(array $params): array
    {
        $slotMinutes = (int) $params['slot_minutes'];
        $slotCount = (int) ($params['slot_count'] ?? 1);
        $duration = $slotMinutes * $slotCount;
        $date = (string) $params['date'];
        $language = strtoupper((string) $params['language']);
        $instructors = Instructor::query()->with(['languages', 'disciplines'])->where('is_active', true)->get()
            ->filter(static fn (Instructor $instructor): bool => in_array((string) $params['discipline'], $instructor->disciplineValues(), true) && in_array($language, $instructor->languageCodes(), true))
            ->values();
        $loads = $this->schedule->dayLoads($date, $instructors->map(static fn (Instructor $instructor): string => (string) $instructor->id)->all());
        $dayEnd = TimeRange::toMinutes((string) config('ski_school.board.day_end'));
        $slots = [];

        for ($minute = TimeRange::toMinutes((string) config('ski_school.board.day_start')); $minute + $duration <= $dayEnd; $minute += $slotMinutes) {
            $range = new TimeRange($minute, $minute + $duration);
            $free = $instructors->filter(fn (Instructor $instructor): bool => $this->schedule->conflict($loads[(string) $instructor->id], $range) === null)->count();

            if ($free > 0 && ! ($date === now()->toDateString() && $minute <= now()->hour * 60 + now()->minute)) {
                $slots[] = ['start' => $range->start(), 'end' => $range->end(), 'available_instructors' => $free];
            }
        }

        return [
            'date' => $date,
            'slots' => $slots,
            'instructors' => $instructors->map(static fn (Instructor $instructor): array => [
                'id' => (string) $instructor->id,
                'display_name' => $instructor->first_name.' '.mb_substr($instructor->last_name, 0, 1).'.',
                'languages' => $instructor->languageCodes(),
                'disciplines' => $instructor->disciplineValues(),
            ])->all(),
        ];
    }

    /**
     * Weekly courses that still have a free place.
     *
     * @param  array<string, mixed>  $filters  discipline, language, level_id, date_from
     * @return list<array<string, mixed>>
     */
    public function courseOffer(array $filters): array
    {
        $regime = $this->settings->vatRegime();
        $courses = GroupCourse::query()->with('level')
            ->withCount(['enrollments as enrolled_count' => static fn ($query) => $query->where('status', EnrollmentStatus::ENROLLED->value)])
            ->whereIn('status', [CourseStatus::OPEN->value, CourseStatus::RUNNING->value])
            ->whereDate('end_date', '>=', (string) ($filters['date_from'] ?? now()->toDateString()))
            ->when($filters['discipline'] ?? null, static fn ($query, string $v) => $query->where('discipline', $v))
            ->when($filters['language'] ?? null, static fn ($query, string $v) => $query->where('language_code', strtoupper($v)))
            ->when($filters['level_id'] ?? null, static fn ($query, string $v) => $query->where('level_id', $v))
            ->orderBy('week_start')->get();

        return $courses->filter(static fn (GroupCourse $course): bool => (int) $course->enrolled_count < $course->capacity)->map(static fn (GroupCourse $course): array => [
            'id' => (string) $course->id,
            'code' => $course->code,
            'discipline' => $course->discipline->value,
            'level_id' => (string) $course->level_id,
            'level_label_key' => $course->level?->labelKey(),
            'level_color' => $course->level?->color,
            'language_code' => $course->language_code,
            'week_start' => $course->week_start->toDateString(),
            'end_date' => $course->end_date->toDateString(),
            'daily_start_time' => $course->daily_start_time,
            'duration_minutes' => $course->duration_minutes,
            'meeting_point' => $course->meeting_point,
            'price_net' => $course->price_net,
            'free_places' => $course->capacity - (int) $course->enrolled_count,
            'vat_legal_reference' => $course->vat_regime->legalReference() ?? $regime->legalReference(),
        ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $data  validated request data
     * @return array<string, mixed>
     *
     * @throws SkiSchoolException invalid_slot, requested_instructor_unavailable, no_instructor_available
     */
    public function bookLesson(array $data): array
    {
        $data['items'] = $this->priceItems((array) ($data['items'] ?? []));
        $data['source'] = 'PUBLIC_PORTAL';
        $data['auto_assign'] = true;
        unset($data['price_net'], $data['instructor_id'], $data['customer_contact_id']);

        $lesson = $this->lessons->book(BookLessonCommand::fromArray($data));
        $lesson->load(['packageItems', 'instructor:id,first_name']);

        return [
            'reference' => $lesson->reference,
            'status' => $lesson->status->value,
            'lesson_date' => $lesson->lesson_date->toDateString(),
            'start_time' => $lesson->start_time,
            'end_time' => $lesson->end_time,
            'discipline' => $lesson->discipline->value,
            'language_code' => $lesson->language_code,
            'instructor_first_name' => $lesson->instructor?->first_name,
            'meeting_point' => $lesson->meeting_point,
            'price_net' => $lesson->price_net,
            'vat_legal_reference' => $lesson->vat_regime->legalReference(),
            'totals' => $this->totals((float) $lesson->price_net, $lesson->vat_regime, $lesson->packageItems),
        ];
    }

    /**
     * @param  array<string, mixed>  $data  validated request data (course_id is set from the route)
     * @return array<string, mixed>
     *
     * @throws SkiSchoolException course_not_found, course_closed, course_full
     */
    public function enroll(array $data): array
    {
        $data['items'] = $this->priceItems((array) ($data['items'] ?? []));
        $data['source'] = 'PUBLIC_PORTAL';
        unset($data['price_net'], $data['contact_id']);

        $enrollment = $this->courses->enroll(EnrollStudentCommand::fromArray($data));
        $enrollment->load(['packageItems', 'course.level']);
        $course = $enrollment->course;

        return [
            'reference' => $enrollment->booking_reference,
            'course_code' => $course->code,
            'level_label_key' => $course->level?->labelKey(),
            'week_start' => $course->week_start->toDateString(),
            'end_date' => $course->end_date->toDateString(),
            'daily_start_time' => $course->daily_start_time,
            'meeting_point' => $course->meeting_point,
            'price_net' => $enrollment->price_net,
            'vat_legal_reference' => $course->vat_regime->legalReference(),
            'totals' => $this->totals((float) $enrollment->price_net, $course->vat_regime, $enrollment->packageItems),
        ];
    }

    /**
     * @param  array<int, mixed>  $items
     * @return list<array<string, mixed>>
     */
    private function priceItems(array $items): array
    {
        $priced = [];

        foreach ($items as $item) {
            $type = PackageItemType::tryFrom((string) ($item['item_type'] ?? '')) ?? throw SkiSchoolException::because('invalid_item_type');
            $priced[] = [
                'item_type' => $type->value,
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'days' => max(1, (int) ($item['days'] ?? 1)),
                'unit_price_net' => $this->addonPrice($type),
            ];
        }

        return $priced;
    }

    private function addonPrice(PackageItemType $type): float
    {
        return (float) config('ski_school.addon_prices.'.$type->value, 0.0);
    }

    /**
     * @param  iterable<int, PackageItem>  $items
     * @return array{net_cents: int, vat_cents: int, gross_cents: int}
     */
    private function totals(float $instructionNet, VatRegime $regime, iterable $items): array
    {
        $rows = [];

        foreach ($items as $item) {
            $rows[] = ['item_type' => $item->item_type->value, 'description' => null, 'quantity' => (float) $item->quantity, 'days' => $item->days, 'unit_price_net' => (float) $item->unit_price_net, 'vat_rate' => (float) $item->vat_rate];
        }

        return $this->pricing->totals($this->pricing->lines('', $instructionNet, $regime, $rows));
    }
}

<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Application\Queries;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Modules\SkiOutdoorSchool\Domain\Enums\EnrollmentStatus;
use Modules\SkiOutdoorSchool\Models\CourseEnrollment;
use Modules\SkiOutdoorSchool\Models\CourseLevel;
use Modules\SkiOutdoorSchool\Models\GroupCourse;
use Modules\SkiOutdoorSchool\Models\Instructor;
use Modules\SkiOutdoorSchool\Models\PayoutStatement;
use Modules\SkiOutdoorSchool\Models\SkiLesson;

/**
 * Read models of the lists of the module: instructors, private lessons, group courses with their level board, payout statements.
 *
 * @phpstan-type Filters array<string, mixed>
 */
final class SchoolQueries
{
    /**
     * @param  Filters  $filters  search, discipline, language, is_active
     * @return LengthAwarePaginator<int, Model>
     */
    public function instructors(array $filters, int $perPage): LengthAwarePaginator
    {
        return Instructor::query()->with(['languages', 'disciplines'])
            ->when(isset($filters['is_active']) && $filters['is_active'] !== '', static fn (Builder $q) => $q->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN)))
            ->when($filters['discipline'] ?? null, static fn (Builder $q, string $v) => $q->whereHas('disciplines', static fn (Builder $d) => $d->where('discipline', $v)))
            ->when($filters['language'] ?? null, static fn (Builder $q, string $v) => $q->whereHas('languages', static fn (Builder $d) => $d->where('language_code', strtoupper($v))))
            ->when($filters['search'] ?? null, fn (Builder $q, string $v) => $this->like($q, ['first_name', 'last_name', 'email', 'license_number'], $v))
            ->orderBy('last_name')->orderBy('first_name')
            ->paginate($perPage);
    }

    public function instructor(string $id): Instructor
    {
        return Instructor::query()->with(['languages', 'disciplines', 'availabilities' => static fn ($q) => $q->whereDate('slot_date', '>=', now()->toDateString())->orderBy('slot_date')])->findOrFail($id);
    }

    /**
     * @param  Filters  $filters  status, discipline, instructor_id, source, unassigned, date_from, date_to, search
     * @return LengthAwarePaginator<int, Model>
     */
    public function lessons(array $filters, int $perPage): LengthAwarePaginator
    {
        return SkiLesson::query()->with(['instructor:id,first_name,last_name', 'packageItems'])
            ->when($filters['status'] ?? null, static fn (Builder $q, string $v) => $q->where('status', $v))
            ->when($filters['discipline'] ?? null, static fn (Builder $q, string $v) => $q->where('discipline', $v))
            ->when($filters['instructor_id'] ?? null, static fn (Builder $q, string $v) => $q->where('instructor_id', $v))
            ->when($filters['source'] ?? null, static fn (Builder $q, string $v) => $q->where('source', $v))
            ->when(filter_var($filters['unassigned'] ?? false, FILTER_VALIDATE_BOOLEAN), static fn (Builder $q) => $q->whereNull('instructor_id'))
            ->when($filters['date_from'] ?? null, static fn (Builder $q, string $v) => $q->whereDate('lesson_date', '>=', $v))
            ->when($filters['date_to'] ?? null, static fn (Builder $q, string $v) => $q->whereDate('lesson_date', '<=', $v))
            ->when($filters['search'] ?? null, fn (Builder $q, string $v) => $this->like($q, ['reference', 'customer_name', 'customer_email'], $v))
            ->orderByDesc('lesson_date')->orderBy('start_time')->orderByDesc('id')
            ->paginate($perPage);
    }

    public function lesson(string $id): SkiLesson
    {
        return SkiLesson::query()->with(['instructor:id,first_name,last_name', 'packageItems'])->findOrFail($id);
    }

    /**
     * @param  Filters  $filters  week_start, discipline, level_id, status, instructor_id, search
     * @return LengthAwarePaginator<int, GroupCourse>
     */
    public function courses(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->courseQuery($filters)->orderByDesc('week_start')->orderBy('code')->paginate($perPage);
    }

    public function course(string $id): GroupCourse
    {
        return GroupCourse::query()->with([
            'level', 'instructor:id,first_name,last_name',
            'enrollments' => static fn ($q) => $q->where('status', EnrollmentStatus::ENROLLED->value)->orderBy('bib_number'),
            'enrollments.packageItems', 'enrollments.attendances', 'enrollments.transfers',
        ])->withCount(['enrollments as enrolled_count' => static fn ($q) => $q->where('status', EnrollmentStatus::ENROLLED->value)])->findOrFail($id);
    }

    /**
     * The level board of one week: every group of the week ordered by the level taxonomy with its students (the screen where students are
     * dragged between groups after the Monday evaluation).
     *
     * @param  Filters  $filters  week_start (required), discipline, language
     * @return array{week_start: string, groups: Collection<int, GroupCourse>, levels: Collection<int, CourseLevel>}
     */
    public function levelBoard(array $filters): array
    {
        $groups = $this->courseQuery($filters)
            ->with(['enrollments' => static fn ($q) => $q->where('status', EnrollmentStatus::ENROLLED->value)->orderBy('bib_number'), 'enrollments.attendances'])
            ->when($filters['language'] ?? null, static fn (Builder $q, string $v) => $q->where('language_code', strtoupper($v)))
            ->get()
            ->sortBy(static fn (GroupCourse $course): string => sprintf('%05d-%s', $course->level->sort_order, $course->code))
            ->values();

        return ['week_start' => (string) $filters['week_start'], 'groups' => $groups, 'levels' => CourseLevel::query()->where('is_active', true)->orderBy('system')->orderBy('sort_order')->get()];
    }

    public function enrollment(string $id): CourseEnrollment
    {
        return CourseEnrollment::query()->with(['packageItems', 'attendances', 'transfers', 'course'])->findOrFail($id);
    }

    /**
     * @param  Filters  $filters  month (YYYY-MM), instructor_id, status
     * @return Collection<int, PayoutStatement>
     */
    public function statements(array $filters): Collection
    {
        return PayoutStatement::query()->with('instructor:id,first_name,last_name')
            ->when($filters['month'] ?? null, static fn (Builder $q, string $v) => $q->where('period_month', $v))
            ->when($filters['instructor_id'] ?? null, static fn (Builder $q, string $v) => $q->where('instructor_id', $v))
            ->when($filters['status'] ?? null, static fn (Builder $q, string $v) => $q->where('status', $v))
            ->orderByDesc('period_month')->orderBy('reference')
            ->get();
    }

    public function statement(string $id): PayoutStatement
    {
        return PayoutStatement::query()->with(['instructor', 'commissions' => static fn ($q) => $q->orderBy('work_date')])->findOrFail($id);
    }

    /**
     * @param  Filters  $filters
     * @return Builder<GroupCourse>
     */
    private function courseQuery(array $filters): Builder
    {
        return GroupCourse::query()->with(['level', 'instructor:id,first_name,last_name'])
            ->withCount(['enrollments as enrolled_count' => static fn ($q) => $q->where('status', EnrollmentStatus::ENROLLED->value)])
            ->when($filters['week_start'] ?? null, static fn (Builder $q, string $v) => $q->whereDate('week_start', $v))
            ->when($filters['discipline'] ?? null, static fn (Builder $q, string $v) => $q->where('discipline', $v))
            ->when($filters['level_id'] ?? null, static fn (Builder $q, string $v) => $q->where('level_id', $v))
            ->when($filters['status'] ?? null, static fn (Builder $q, string $v) => $q->where('status', $v))
            ->when($filters['instructor_id'] ?? null, static fn (Builder $q, string $v) => $q->where('instructor_id', $v))
            ->when($filters['search'] ?? null, fn (Builder $q, string $v) => $this->like($q, ['code', 'meeting_point'], $v));
    }

    /**
     * Case-insensitive contains on several columns, portable between SQLite and PostgreSQL (no ILIKE).
     *
     * @param  Builder<*>  $query
     * @param  list<string>  $columns
     */
    private function like(Builder $query, array $columns, string $term): Builder
    {
        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($term)).'%';

        return $query->where(static function (Builder $inner) use ($columns, $pattern): void {
            foreach ($columns as $column) {
                $inner->orWhereRaw('LOWER('.$column.') LIKE ?', [$pattern]);
            }
        });
    }
}

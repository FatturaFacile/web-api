<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Services;

use App\Support\Database\TransactionLock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\TalentLearning\Contracts\HrDirectory;
use Modules\TalentLearning\Models\LmsCertificate;
use Modules\TalentLearning\Models\LmsCourse;
use Modules\TalentLearning\Models\LmsEnrolment;
use Modules\TalentLearning\Models\LmsLearningPath;
use Modules\TalentLearning\Models\LmsPathCourse;
use Modules\TalentLearning\Support\Rejection;

/**
 * The learning catalogue: courses, learning paths, enrolments with progress, completion certificates and the compliance
 * with mandatory training. A mandatory course applies to every active employee; a certificate is valid until its
 * expiry date (never, when the course has no validity).
 */
final readonly class LearningService
{
    public function __construct(private HrDirectory $directory) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createCourse(string $tenantId, array $data): LmsCourse
    {
        $this->assertCodeFree($tenantId, (string) $data['code'], null);

        return LmsCourse::create([...$data, 'tenant_id' => $tenantId, 'status' => LmsCourse::Draft])->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCourse(LmsCourse $course, array $data): LmsCourse
    {
        if (isset($data['code'])) {
            $this->assertCodeFree((string) $course->tenant_id, (string) $data['code'], $course);
        }

        $course->update(array_diff_key($data, ['status' => true]));

        return $course->refresh();
    }

    public function transitionCourse(LmsCourse $course, string $status): LmsCourse
    {
        $allowed = [
            LmsCourse::Draft => [LmsCourse::Published],
            LmsCourse::Published => [LmsCourse::Archived],
            LmsCourse::Archived => [LmsCourse::Published],
        ];

        if (! in_array($status, $allowed[$course->status] ?? [], true)) {
            throw Rejection::on('status', 'learning.course_transition', ['from' => $course->status, 'to' => $status]);
        }

        if ($status === LmsCourse::Published && (float) $course->duration_hours <= 0) {
            throw Rejection::on('status', 'learning.course_needs_duration');
        }

        $course->update(['status' => $status]);

        return $course->refresh();
    }

    public function deleteCourse(LmsCourse $course): void
    {
        if ($course->enrolments()->exists() || LmsPathCourse::query()->where('course_id', $course->id)->exists()) {
            throw Rejection::on('id', 'learning.course_in_use');
        }

        $course->delete();
    }

    /**
     * @param  array<string, mixed>  $data  name, description and course_ids (ordered)
     */
    public function savePath(string $tenantId, array $data, ?LmsLearningPath $path): LmsLearningPath
    {
        $courseIds = array_key_exists('course_ids', $data) ? array_values($data['course_ids']) : null;

        if ($courseIds !== null) {
            if (count($courseIds) !== count(array_unique($courseIds))) {
                throw Rejection::on('course_ids', 'learning.path_duplicate_course');
            }

            if (LmsCourse::query()->where('tenant_id', $tenantId)->whereIn('id', $courseIds)->count() !== count($courseIds)) {
                throw Rejection::on('course_ids', 'learning.course_missing');
            }
        }

        return DB::transaction(function () use ($tenantId, $data, $path, $courseIds): LmsLearningPath {
            $fields = array_intersect_key($data, array_flip(['name', 'description']));
            $path = $path === null ? LmsLearningPath::create([...$fields, 'tenant_id' => $tenantId, 'status' => LmsCourse::Draft]) : tap($path)->update($fields);

            if ($courseIds !== null) {
                LmsPathCourse::query()->where('learning_path_id', $path->id)->get()->each->delete();

                foreach ($courseIds as $index => $courseId) {
                    LmsPathCourse::create(['tenant_id' => $tenantId, 'learning_path_id' => $path->id, 'course_id' => $courseId, 'position' => $index + 1]);
                }
            }

            return $path->refresh();
        });
    }

    public function transitionPath(LmsLearningPath $path, string $status): LmsLearningPath
    {
        if ($status === LmsCourse::Published) {
            $steps = $path->steps()->with('course')->get();

            if ($steps->isEmpty() || $steps->contains(static fn (LmsPathCourse $step): bool => $step->course?->status !== LmsCourse::Published)) {
                throw Rejection::on('status', 'learning.path_needs_published_courses');
            }
        }

        $path->update(['status' => $status]);

        return $path->refresh();
    }

    public function deletePath(LmsLearningPath $path): void
    {
        if (LmsEnrolment::query()->where('learning_path_id', $path->id)->exists()) {
            throw Rejection::on('id', 'learning.path_in_use');
        }

        DB::transaction(function () use ($path): void {
            $path->steps()->get()->each->delete();
            $path->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $data  course_id, employee_id, due_on
     */
    public function enrol(string $tenantId, array $data, ?string $pathId = null): LmsEnrolment
    {
        $course = LmsCourse::query()->where('tenant_id', $tenantId)->find($data['course_id']);

        if ($course === null) {
            throw Rejection::on('course_id', 'learning.course_missing');
        }

        if ($course->status !== LmsCourse::Published) {
            throw Rejection::on('course_id', 'learning.course_not_published');
        }

        $employeeId = (string) $data['employee_id'];
        $employee = $this->directory->employee($tenantId, $employeeId);

        if ($employee === null || ! $employee->isActive) {
            throw Rejection::on('employee_id', 'employee_missing');
        }

        $open = LmsEnrolment::query()->where('course_id', $course->id)->where('employee_id', $employeeId)->whereIn('status', [LmsEnrolment::Enrolled, LmsEnrolment::InProgress])->exists();

        if ($open) {
            throw Rejection::on('course_id', 'learning.already_enrolled');
        }

        if ($this->validCertificate($tenantId, $course->id, $employeeId) !== null) {
            throw Rejection::on('course_id', 'learning.already_certified');
        }

        return LmsEnrolment::create([
            'tenant_id' => $tenantId,
            'course_id' => $course->id,
            'employee_id' => $employeeId,
            'learning_path_id' => $pathId,
            'due_on' => $data['due_on'] ?? null,
            'status' => LmsEnrolment::Enrolled,
            'progress_percent' => 0,
        ])->refresh();
    }

    /**
     * Enrols employees in every course of a published path they are not already taking or certified for.
     *
     * @param  list<string>  $employeeIds
     * @return list<LmsEnrolment>
     */
    public function enrolInPath(LmsLearningPath $path, array $employeeIds, ?string $dueOn): array
    {
        if ($path->status !== LmsCourse::Published) {
            throw Rejection::on('id', 'learning.path_not_published');
        }

        $tenantId = (string) $path->tenant_id;

        return DB::transaction(function () use ($path, $tenantId, $employeeIds, $dueOn): array {
            $created = [];

            foreach ($employeeIds as $employeeId) {
                if (($employee = $this->directory->employee($tenantId, $employeeId)) === null || ! $employee->isActive) {
                    throw Rejection::on('employee_ids', 'employee_missing');
                }

                foreach ($path->steps()->get() as $step) {
                    $open = LmsEnrolment::query()->where('course_id', $step->course_id)->where('employee_id', $employeeId)->whereIn('status', [LmsEnrolment::Enrolled, LmsEnrolment::InProgress])->exists();

                    if ($open || $this->validCertificate($tenantId, (string) $step->course_id, $employeeId) !== null) {
                        continue;
                    }

                    $created[] = $this->enrol($tenantId, ['course_id' => $step->course_id, 'employee_id' => $employeeId, 'due_on' => $dueOn], (string) $path->id);
                }
            }

            return $created;
        });
    }

    /**
     * Progress only moves forward; the first progress starts the enrolment. Reaching 100 does not complete it:
     * completion records the score and issues the certificate.
     */
    public function recordProgress(LmsEnrolment $enrolment, int $percent): LmsEnrolment
    {
        $this->assertOpen($enrolment);

        if ($percent < $enrolment->progress_percent) {
            throw Rejection::on('progress_percent', 'learning.progress_decrease', ['current' => $enrolment->progress_percent]);
        }

        $enrolment->update([
            'progress_percent' => $percent,
            'status' => $percent > 0 ? LmsEnrolment::InProgress : $enrolment->status,
            'started_at' => $enrolment->started_at ?? ($percent > 0 ? Carbon::now() : null),
        ]);

        return $enrolment->refresh();
    }

    public function complete(LmsEnrolment $enrolment, ?float $score): LmsEnrolment
    {
        return DB::transaction(function () use ($enrolment, $score): LmsEnrolment {
            $enrolment = LmsEnrolment::query()->whereKey($enrolment->id)->lockForUpdate()->firstOrFail();
            $this->assertOpen($enrolment);
            $course = $enrolment->course()->firstOrFail();

            if ($course->pass_score !== null) {
                if ($score === null) {
                    throw Rejection::on('score', 'learning.score_required');
                }

                if ($score < $course->pass_score) {
                    throw Rejection::on('score', 'learning.score_below_pass', ['score' => $score, 'pass' => $course->pass_score]);
                }
            }

            $now = Carbon::now();
            $enrolment->update(['status' => LmsEnrolment::Completed, 'progress_percent' => 100, 'completed_at' => $now, 'started_at' => $enrolment->started_at ?? $now, 'score' => $score]);
            $this->issueCertificate($enrolment, $course);

            return $enrolment->refresh();
        });
    }

    public function cancel(LmsEnrolment $enrolment): LmsEnrolment
    {
        $this->assertOpen($enrolment);
        $enrolment->update(['status' => LmsEnrolment::Cancelled]);

        return $enrolment->refresh();
    }

    public function validCertificate(string $tenantId, string $courseId, string $employeeId): ?LmsCertificate
    {
        return LmsCertificate::query()->where('tenant_id', $tenantId)->where('course_id', $courseId)->where('employee_id', $employeeId)
            ->where(static fn ($query) => $query->whereNull('expires_on')->orWhere('expires_on', '>=', Carbon::today()->toDateString()))
            ->first();
    }

    /**
     * Compliance with mandatory training: for each published mandatory course, how many active employees hold a valid
     * certificate. With a course id the employees who are not compliant are listed too.
     *
     * @return array<string, mixed>
     */
    public function compliance(string $tenantId, ?string $courseId = null): array
    {
        $employees = $this->directory->activeEmployees($tenantId);
        $today = Carbon::today();
        $courses = LmsCourse::query()->where('tenant_id', $tenantId)->where('is_mandatory', true)->where('status', LmsCourse::Published)
            ->when($courseId !== null, static fn ($query) => $query->whereKey($courseId))->orderBy('title')->get();
        $rows = [];
        $required = $compliantTotal = 0;

        foreach ($courses as $course) {
            $certificates = LmsCertificate::query()->where('course_id', $course->id)->get()->groupBy('employee_id');
            $open = LmsEnrolment::query()->where('course_id', $course->id)->whereIn('status', [LmsEnrolment::Enrolled, LmsEnrolment::InProgress])->get()->keyBy('employee_id');
            $counts = ['compliant' => 0, 'in_progress' => 0, 'overdue' => 0, 'expired' => 0, 'missing' => 0];
            $gaps = [];

            foreach ($employees as $employee) {
                $own = $certificates->get($employee->id, collect());
                $valid = $own->contains(static fn (LmsCertificate $certificate): bool => $certificate->expires_on === null || $certificate->expires_on->greaterThanOrEqualTo($today));
                $enrolment = $open->get($employee->id);

                $state = match (true) {
                    $valid => 'compliant',
                    $enrolment !== null && $enrolment->due_on !== null && $enrolment->due_on->lessThan($today) => 'overdue',
                    $enrolment !== null => 'in_progress',
                    $own->isNotEmpty() => 'expired',
                    default => 'missing',
                };
                $counts[$state]++;

                if (! $valid) {
                    $gaps[] = ['employee' => $employee->toArray(), 'state' => $state, 'due_on' => $enrolment?->due_on?->toDateString()];
                }
            }

            $total = count($employees);
            $required += $total;
            $compliantTotal += $counts['compliant'];
            $rows[] = [
                'course' => ['id' => $course->id, 'code' => $course->code, 'title' => $course->title],
                'employees_total' => $total,
                ...$counts,
                'compliance_percent' => $total > 0 ? round($counts['compliant'] / $total * 100, 1) : 100.0,
                ...($courseId !== null ? ['non_compliant' => $gaps] : []),
            ];
        }

        return [
            'courses' => $rows,
            'compliance_percent' => $required > 0 ? round($compliantTotal / $required * 100, 1) : 100.0,
        ];
    }

    /**
     * Counts for the dashboard.
     *
     * @return array<string, mixed>
     */
    public function summary(string $tenantId): array
    {
        $today = Carbon::today();
        $enrolments = LmsEnrolment::query()->where('tenant_id', $tenantId)->get();
        $byStatus = [LmsEnrolment::Enrolled => 0, LmsEnrolment::InProgress => 0, LmsEnrolment::Completed => 0, LmsEnrolment::Cancelled => 0];

        foreach ($enrolments as $enrolment) {
            $byStatus[$enrolment->status]++;
        }

        $considered = $enrolments->count() - $byStatus[LmsEnrolment::Cancelled];
        $overdue = $enrolments->filter(static fn (LmsEnrolment $enrolment): bool => in_array($enrolment->status, [LmsEnrolment::Enrolled, LmsEnrolment::InProgress], true) && $enrolment->due_on !== null && $enrolment->due_on->lessThan($today))->count();
        $certificates = LmsCertificate::query()->where('tenant_id', $tenantId)->whereNotNull('expires_on')->get();

        return [
            'courses_published' => LmsCourse::query()->where('tenant_id', $tenantId)->where('status', LmsCourse::Published)->count(),
            'learning_paths_published' => LmsLearningPath::query()->where('tenant_id', $tenantId)->where('status', LmsCourse::Published)->count(),
            'enrolments_by_status' => $byStatus,
            'completion_rate_percent' => $considered > 0 ? round($byStatus[LmsEnrolment::Completed] / $considered * 100, 1) : 0.0,
            'overdue_enrolments' => $overdue,
            'certificates_issued' => LmsCertificate::query()->where('tenant_id', $tenantId)->count(),
            'certificates_expired' => $certificates->filter(static fn (LmsCertificate $certificate): bool => $certificate->expires_on->lessThan($today))->count(),
            'certificates_expiring_90_days' => $certificates->filter(static fn (LmsCertificate $certificate): bool => $certificate->expires_on->betweenIncluded($today, $today->copy()->addDays(90)))->count(),
            'mandatory_compliance_percent' => $this->compliance($tenantId)['compliance_percent'],
        ];
    }

    private function issueCertificate(LmsEnrolment $enrolment, LmsCourse $course): LmsCertificate
    {
        $tenantId = (string) $enrolment->tenant_id;
        TransactionLock::acquire('talent-certificate:'.$tenantId);

        $issued = Carbon::today();
        $sequence = LmsCertificate::withoutGlobalScopes()->withTrashed()->where('tenant_id', $tenantId)->count() + 1;

        return LmsCertificate::create([
            'tenant_id' => $tenantId,
            'enrolment_id' => $enrolment->id,
            'course_id' => $course->id,
            'employee_id' => $enrolment->employee_id,
            'certificate_number' => sprintf('CERT-%d-%05d', $issued->year, $sequence),
            'issued_on' => $issued->toDateString(),
            'expires_on' => $course->validity_months !== null ? $issued->copy()->addMonthsNoOverflow($course->validity_months)->toDateString() : null,
        ]);
    }

    private function assertOpen(LmsEnrolment $enrolment): void
    {
        if (! in_array($enrolment->status, [LmsEnrolment::Enrolled, LmsEnrolment::InProgress], true)) {
            throw Rejection::on('status', 'learning.enrolment_closed', ['status' => $enrolment->status]);
        }
    }

    private function assertCodeFree(string $tenantId, string $code, ?LmsCourse $current): void
    {
        $taken = LmsCourse::query()->where('tenant_id', $tenantId)->where('code', $code)->when($current !== null, static fn ($query) => $query->whereKeyNot($current->id))->exists();

        if ($taken) {
            throw Rejection::on('code', 'learning.code_taken', ['code' => $code]);
        }
    }
}

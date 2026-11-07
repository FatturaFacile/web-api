<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\Shifts;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Modules\HumanResources\Exceptions\ShiftRuleViolationException;
use Modules\HumanResources\Mail\ShiftPlanPublishedMail;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\Shift;
use Modules\HumanResources\Models\ShiftPublication;

/**
 * Publishes the drafts of a period. A plan that breaks the working time rules is published only when the planner confirms
 * it with a reason, for example a permit of the authority: the reason and the findings stay on the publication.
 */
final class ShiftPublicationService
{
    public function __construct(private readonly ShiftRuleValidator $validator) {}

    public function publish(User $actor, string $from, string $to, ?string $departmentId, ?string $acknowledgementReason): ShiftPublication
    {
        $violations = $this->validator->validate($from, $to, $departmentId);
        $blocking = array_values(array_filter($violations, static fn (ShiftViolation $violation): bool => $violation->isBlocking));

        if ($blocking !== [] && $acknowledgementReason === null) {
            throw new ShiftRuleViolationException($blocking);
        }

        $publication = DB::transaction(function () use ($actor, $from, $to, $departmentId, $acknowledgementReason, $violations): ShiftPublication {
            $drafts = Shift::query()
                ->betweenDays($from, $to)
                ->where('status', Shift::StatusDraft)
                ->when($departmentId, static fn ($query) => $query->where('department_id', $departmentId))
                ->lockForUpdate()
                ->get();

            $publication = ShiftPublication::create([
                'period_from' => $from,
                'period_to' => $to,
                'department_id' => $departmentId,
                'shift_count' => $drafts->count(),
                'employee_count' => $drafts->pluck('employee_id')->unique()->count(),
                'violations' => array_map(static fn (ShiftViolation $violation): array => $violation->toArray(), $violations),
                'acknowledgement_reason' => $acknowledgementReason,
                'published_by' => $actor->id,
                'published_at' => CarbonImmutable::now(),
            ]);

            foreach ($drafts as $shift) {
                $shift->update(['status' => Shift::StatusPublished, 'shift_publication_id' => $publication->id]);
            }

            return $publication;
        });

        $this->notifyEmployees($publication);

        return $publication;
    }

    /**
     * Each employee receives only the own shifts of the publication.
     */
    private function notifyEmployees(ShiftPublication $publication): void
    {
        $shifts = Shift::query()->with('type')->where('shift_publication_id', $publication->id)->orderBy('work_date')->get()->groupBy('employee_id');
        $employees = Employee::query()->with('user')->whereIn('id', $shifts->keys())->get();

        foreach ($employees as $employee) {
            $address = $employee->work_email ?: $employee->user?->email;

            if ($address === null || $address === '') {
                continue;
            }

            $lines = $shifts->get($employee->id)->map(static fn (Shift $shift): array => [
                'date' => $shift->work_date->format('d.m.Y'),
                'name' => (string) $shift->type?->name,
                'time' => $shift->starts_at === null ? null : $shift->starts_at->setTimezone('Europe/Zurich')->format('H:i').' - '.$shift->ends_at->setTimezone('Europe/Zurich')->format('H:i'),
            ])->all();

            Mail::to($address)
                ->locale($employee->user?->locale ?? 'it')
                ->queue(new ShiftPlanPublishedMail((string) $employee->full_name, $publication->period_from->format('d.m.Y'), $publication->period_to->format('d.m.Y'), array_values($lines)));
        }
    }
}

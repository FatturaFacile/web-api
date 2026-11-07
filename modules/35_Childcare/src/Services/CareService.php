<?php

declare(strict_types=1);

namespace Modules\Childcare\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Childcare\Models\Attendance;
use Modules\Childcare\Models\Child;
use Modules\Childcare\Models\Note;
use Modules\Childcare\Models\ReferencePerson;
use Modules\Childcare\Models\StaffShift;
use Modules\Childcare\Models\WeeklyPlan;

/**
 * Daily care: the weekly plans, the meal counts of the kitchen with allergies, staff hours and the daily report.
 */
final class CareService
{
    /**
     * The plan of the child valid on the day, if any.
     */
    public function planOn(Child $child, CarbonImmutable $day, ?Collection $plans = null): ?WeeklyPlan
    {
        $plans ??= WeeklyPlan::query()->where('child_id', $child->id)->get();

        return $plans
            ->filter(static fn (WeeklyPlan $plan): bool => $plan->child_id === $child->id && $plan->valid_from->lte($day) && ($plan->valid_to === null || $plan->valid_to->gte($day)))
            ->sortByDesc(static fn (WeeklyPlan $plan): string => $plan->valid_from->toDateString())
            ->first();
    }

    /**
     * Meals to cook on a day: planned children who are not absent, per meal, with the allergies the kitchen must know.
     *
     * @return array{date: string, meals: array<string, int>, dietary_requirements: list<array<string, ?string>>}
     */
    public function mealCounts(string $date): array
    {
        $day = CarbonImmutable::parse($date);
        $children = Child::query()->where('status', 'active')->get()->filter(static fn (Child $child): bool => $child->isEnrolledOn($day));
        $plans = WeeklyPlan::query()->whereIn('child_id', $children->pluck('id'))->get();
        $absent = Attendance::query()->whereDate('date', $day->toDateString())->where('status', 'absent')->pluck('child_id')->all();
        $meals = array_fill_keys(WeeklyPlan::Meals, 0);
        $requirements = [];

        foreach ($children as $child) {
            $planned = $this->planOn($child, $day, $plans)?->dayOf($day)['meals'] ?? [];

            if ($planned === [] || in_array($child->id, $absent, true)) {
                continue;
            }

            foreach ($planned as $meal) {
                $meals[$meal] = ($meals[$meal] ?? 0) + 1;
            }

            if ($child->allergies !== null || $child->dietary_notes !== null) {
                $requirements[] = ['reference_code' => $child->reference_code, 'name' => "{$child->first_name} {$child->last_name}", 'allergies' => $child->allergies, 'dietary_notes' => $child->dietary_notes];
            }
        }

        return ['date' => $day->toDateString(), 'meals' => $meals, 'dietary_requirements' => $requirements];
    }

    /**
     * Minutes worked per staff member in a month.
     *
     * @return list<array{user_id: string, name: ?string, shifts: int, minutes: int}>
     */
    public function staffHours(string $month): array
    {
        $start = CarbonImmutable::parse("{$month}-01");
        $shifts = StaffShift::query()->whereBetween('date', [$start->toDateString(), $start->endOfMonth()->toDateString()])->get()->groupBy('user_id');
        $names = User::query()->whereIn('id', $shifts->keys())->pluck('name', 'id');

        return $shifts->map(static fn (Collection $userShifts, string $userId): array => [
            'user_id' => $userId,
            'name' => $names[$userId] ?? null,
            'shifts' => $userShifts->count(),
            'minutes' => $userShifts->sum(static fn (StaffShift $shift): int => $shift->workedMinutes()),
        ])->values()->all();
    }

    /**
     * What the family sees of a day: times, who picked the child up, planned meals and the notes shared with them.
     *
     * @return array<string, mixed>
     */
    public function dailyReport(Child $child, string $date): array
    {
        $day = CarbonImmutable::parse($date);
        $attendance = Attendance::query()->where('child_id', $child->id)->whereDate('date', $day->toDateString())->first();
        $pickedUpBy = $attendance?->picked_up_by === null ? null : ReferencePerson::query()->find($attendance->picked_up_by)?->full_name;

        return [
            'child_id' => $child->id,
            'date' => $day->toDateString(),
            'status' => $attendance?->status,
            'check_in_at' => $attendance?->check_in_at?->format('H:i'),
            'check_out_at' => $attendance?->check_out_at?->format('H:i'),
            'picked_up_by' => $pickedUpBy,
            'meals' => $this->planOn($child, $day)?->dayOf($day)['meals'] ?? [],
            'notes' => Note::query()->where('child_id', $child->id)->whereDate('date', $day->toDateString())->where('visible_to_family', true)->orderBy('created_at')->get()
                ->map(static fn (Note $note): array => ['category' => $note->category, 'body' => $note->body])->all(),
        ];
    }
}

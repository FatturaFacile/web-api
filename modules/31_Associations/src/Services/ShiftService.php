<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Associations\Exceptions\AssociationException;
use Modules\Associations\Models\Shift;
use Modules\Associations\Models\ShiftAssignment;
use Modules\Associations\Models\ShiftAvailability;
use Modules\Associations\Models\Volunteer;

/**
 * Service shifts of the volunteers: availability, assignment within the places of the shift, substitution, and the
 * coverage that says how many places are still empty.
 */
final class ShiftService
{
    public function setAvailability(Shift $shift, Volunteer $volunteer, bool $available): ShiftAvailability
    {
        return ShiftAvailability::query()->updateOrCreate(['shift_id' => $shift->id, 'volunteer_id' => $volunteer->id], ['is_available' => $available]);
    }

    public function assign(Shift $shift, Volunteer $volunteer): ShiftAssignment
    {
        $this->assertVolunteerActive($volunteer, $shift);

        return DB::transaction(function () use ($shift, $volunteer): ShiftAssignment {
            Shift::query()->whereKey($shift->id)->lockForUpdate()->first();
            $this->assertPlanned($shift);

            if (ShiftAssignment::query()->where('shift_id', $shift->id)->where('volunteer_id', $volunteer->id)->exists()) {
                throw AssociationException::conflict(__('association_governance.shift_already_assigned'));
            }

            if ($this->covered($shift) >= $shift->required_volunteers) {
                throw AssociationException::conflict(__('association_governance.shift_full'));
            }

            return ShiftAssignment::create(['shift_id' => $shift->id, 'volunteer_id' => $volunteer->id, 'status' => ShiftAssignment::Assigned]);
        });
    }

    public function requestSubstitution(ShiftAssignment $assignment): ShiftAssignment
    {
        if ($assignment->status !== ShiftAssignment::Assigned) {
            throw AssociationException::conflict(__('association_governance.assignment_not_active'));
        }

        $assignment->update(['status' => ShiftAssignment::SubstitutionRequested]);

        return $assignment;
    }

    /**
     * Another volunteer takes the place; the replaced assignment stays as history.
     */
    public function substitute(ShiftAssignment $assignment, Volunteer $substitute): ShiftAssignment
    {
        if (! in_array($assignment->status, ShiftAssignment::Covering, true)) {
            throw AssociationException::conflict(__('association_governance.assignment_not_active'));
        }

        $this->assertVolunteerActive($substitute, $assignment->shift);

        return DB::transaction(static function () use ($assignment, $substitute): ShiftAssignment {
            if (ShiftAssignment::query()->where('shift_id', $assignment->shift_id)->where('volunteer_id', $substitute->id)->exists()) {
                throw AssociationException::conflict(__('association_governance.shift_already_assigned'));
            }

            $replacement = ShiftAssignment::create(['shift_id' => $assignment->shift_id, 'volunteer_id' => $substitute->id, 'status' => ShiftAssignment::Assigned]);
            $assignment->update(['status' => ShiftAssignment::Replaced, 'replaced_by_id' => $replacement->id]);

            return $replacement;
        });
    }

    /**
     * @return array{shift_id: string, required: int, covered: int, missing: int, substitutions_requested: int, available_volunteer_ids: list<string>}
     */
    public function coverage(Shift $shift): array
    {
        $assigned = ShiftAssignment::query()->where('shift_id', $shift->id)->pluck('volunteer_id')->all();
        $covered = $this->covered($shift);

        return [
            'shift_id' => $shift->id,
            'required' => $shift->required_volunteers,
            'covered' => $covered,
            'missing' => max(0, $shift->required_volunteers - $covered),
            'substitutions_requested' => ShiftAssignment::query()->where('shift_id', $shift->id)->where('status', ShiftAssignment::SubstitutionRequested)->count(),
            'available_volunteer_ids' => ShiftAvailability::query()->where('shift_id', $shift->id)->where('is_available', true)
                ->whereNotIn('volunteer_id', $assigned)->pluck('volunteer_id')->map(static fn (mixed $id): string => (string) $id)->values()->all(),
        ];
    }

    /**
     * Planned shifts starting in the window that miss volunteers or wait for a substitute.
     *
     * @return Collection<int, Shift>
     */
    public function needingAttention(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Shift::query()->where('status', Shift::Planned)->whereBetween('starts_at', [$from, $to])->orderBy('starts_at')->get()
            ->filter(function (Shift $shift): bool {
                $coverage = $this->coverage($shift);

                return $coverage['missing'] > 0 || $coverage['substitutions_requested'] > 0;
            })->values();
    }

    private function covered(Shift $shift): int
    {
        return ShiftAssignment::query()->where('shift_id', $shift->id)->where('status', ShiftAssignment::Assigned)->count();
    }

    private function assertPlanned(Shift $shift): void
    {
        if ($shift->refresh()->status !== Shift::Planned) {
            throw AssociationException::conflict(__('association_governance.shift_not_planned'));
        }
    }

    private function assertVolunteerActive(Volunteer $volunteer, Shift $shift): void
    {
        $day = $shift->starts_at->toDateString();

        if ($volunteer->started_on->toDateString() > $day || ($volunteer->ended_on !== null && $volunteer->ended_on->toDateString() < $day)) {
            throw new AssociationException(__('association_governance.volunteer_not_active'));
        }
    }
}

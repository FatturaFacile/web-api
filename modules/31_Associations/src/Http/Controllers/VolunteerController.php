<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Associations\Http\Requests\ShiftVolunteerRequest;
use Modules\Associations\Http\Resources\AssociationRecordResource;
use Modules\Associations\Models\Shift;
use Modules\Associations\Models\ShiftAssignment;
use Modules\Associations\Models\Volunteer;
use Modules\Associations\Models\VolunteerTraining;
use Modules\Associations\OpenApi\AssociationGet;
use Modules\Associations\OpenApi\AssociationPost;
use Modules\Associations\Services\ShiftService;
use Modules\Associations\Support\AssociationCapability;

/**
 * Volunteers per year, shifts with availability, coverage and substitutions, and the expiries to watch. Volunteers,
 * trainings and shifts themselves are maintained through the catalogs.
 */
final class VolunteerController extends AssociationController
{
    public function __construct(private readonly ShiftService $shifts) {}

    #[AssociationGet('/api/v1/associations/volunteers/year/{year}', 'listAssociationVolunteersOfYear', 'Volunteers active in a year, regular and occasional', response: 'AssociationRecord')]
    public function ofYear(string $year): JsonResponse
    {
        $this->allow(AssociationCapability::Read);
        $value = max(1900, min(2200, (int) $year));
        $volunteers = Volunteer::query()->with(['member.contact:id,first_name,last_name', 'trainings'])->activeInYear($value)->orderBy('started_on')->get();

        return response()->json(['data' => AssociationRecordResource::collection($volunteers), 'meta' => [
            'year' => $value,
            'regular' => $volunteers->where('is_occasional', false)->count(),
            'occasional' => $volunteers->where('is_occasional', true)->count(),
        ]]);
    }

    #[AssociationGet('/api/v1/associations/volunteers/expiries', 'listAssociationVolunteerExpiries', 'Trainings and insurance coverage expiring within the given days', query: ['days'], response: 'AssociationVolunteerExpiries')]
    public function expiries(Request $request): JsonResponse
    {
        $this->allow(AssociationCapability::Read);
        $limit = CarbonImmutable::today()->addDays(max(1, min(365, $request->integer('days', 30))))->toDateString();

        return response()->json(['data' => [
            'trainings' => AssociationRecordResource::collection(VolunteerTraining::query()->whereNotNull('expires_on')->whereDate('expires_on', '<=', $limit)->orderBy('expires_on')->get()),
            'insurance' => AssociationRecordResource::collection(Volunteer::query()->whereNotNull('insurance_expires_on')->whereDate('insurance_expires_on', '<=', $limit)->orderBy('insurance_expires_on')->get()),
        ]]);
    }

    #[AssociationPost('/api/v1/associations/shifts/{id}/availability', 'setAssociationShiftAvailability', 'Record whether a volunteer is available for a shift', 'AssociationShiftVolunteerRequest', response: 'AssociationRecord')]
    public function availability(ShiftVolunteerRequest $request, string $id): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Manage);

        return new AssociationRecordResource($this->shifts->setAvailability(
            Shift::query()->findOrFail($id),
            Volunteer::query()->findOrFail((string) $request->validated('volunteer_id')),
            $request->boolean('is_available', true),
        ));
    }

    #[AssociationPost('/api/v1/associations/shifts/{id}/assignments', 'assignAssociationShift', 'Put a volunteer on a shift, within its places', 'AssociationShiftVolunteerRequest', response: 'AssociationRecord')]
    public function assign(ShiftVolunteerRequest $request, string $id): JsonResponse
    {
        $this->allow(AssociationCapability::Manage);
        $assignment = $this->shifts->assign(Shift::query()->findOrFail($id), Volunteer::query()->findOrFail((string) $request->validated('volunteer_id')));

        return (new AssociationRecordResource($assignment))->response()->setStatusCode(201);
    }

    #[AssociationGet('/api/v1/associations/shifts/{id}/coverage', 'showAssociationShiftCoverage', 'Places required, covered and missing, pending substitutions and available volunteers', response: 'AssociationShiftCoverage')]
    public function coverage(string $id): JsonResponse
    {
        $this->allow(AssociationCapability::Read);

        return response()->json(['data' => $this->shifts->coverage(Shift::query()->findOrFail($id))]);
    }

    #[AssociationGet('/api/v1/associations/shifts/attention', 'listAssociationShiftsNeedingAttention', 'Planned shifts of the coming days missing volunteers or waiting for a substitute', query: ['days'], response: 'AssociationRecord')]
    public function attention(Request $request): JsonResponse
    {
        $this->allow(AssociationCapability::Read);
        $shifts = $this->shifts->needingAttention(CarbonImmutable::now(), CarbonImmutable::now()->addDays(max(1, min(90, $request->integer('days', 7)))));

        return response()->json(['data' => $shifts->map(fn (Shift $shift): array => ['shift' => new AssociationRecordResource($shift), 'coverage' => $this->shifts->coverage($shift)])->all()]);
    }

    #[AssociationPost('/api/v1/associations/shift-assignments/{id}/substitution-request', 'requestAssociationShiftSubstitution', 'The volunteer cannot come and a substitute is needed', response: 'AssociationRecord')]
    public function requestSubstitution(string $id): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Manage);

        return new AssociationRecordResource($this->shifts->requestSubstitution(ShiftAssignment::query()->findOrFail($id)));
    }

    #[AssociationPost('/api/v1/associations/shift-assignments/{id}/substitute', 'substituteAssociationShiftVolunteer', 'Another volunteer takes the place', 'AssociationShiftVolunteerRequest', response: 'AssociationRecord')]
    public function substitute(ShiftVolunteerRequest $request, string $id): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Manage);

        return new AssociationRecordResource($this->shifts->substitute(
            ShiftAssignment::query()->with('shift')->findOrFail($id),
            Volunteer::query()->findOrFail((string) $request->validated('volunteer_id')),
        ));
    }
}

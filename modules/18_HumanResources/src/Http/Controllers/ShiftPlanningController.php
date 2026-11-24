<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Http\RequestedPerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\HumanResources\Http\Requests\AssignShiftSequenceRequest;
use Modules\HumanResources\Http\Requests\SaveShiftExceptionRequest;
use Modules\HumanResources\Http\Requests\SaveShiftRequest;
use Modules\HumanResources\Http\Requests\SaveShiftSequenceRequest;
use Modules\HumanResources\Http\Requests\SaveShiftTypeRequest;
use Modules\HumanResources\Http\Requests\SaveStaffingNeedRequest;
use Modules\HumanResources\Http\Requests\ShiftPeriodRequest;
use Modules\HumanResources\Http\Resources\HrRecordResource;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\Shift;
use Modules\HumanResources\Models\ShiftException;
use Modules\HumanResources\Models\ShiftSequence;
use Modules\HumanResources\Models\ShiftSequenceAssignment;
use Modules\HumanResources\Models\ShiftType;
use Modules\HumanResources\Models\StaffingNeed;
use Modules\HumanResources\Services\Shifts\ShiftBoardService;
use Modules\HumanResources\Services\Shifts\ShiftPlanner;
use Modules\HumanResources\Services\Shifts\ShiftPublicationService;
use OpenApi\Attributes as OA;

/**
 * Shift planning: rules of the plan, generation, board, publication, and the published plan of an employee.
 */
final class ShiftPlanningController extends Controller
{
    public function __construct(
        private readonly ShiftPlanner $planner,
        private readonly ShiftBoardService $board,
        private readonly ShiftPublicationService $publications,
    ) {}

    /**
     * List shift types.
     */
    #[OA\Get(path: '/api/v1/hr/shift-types', operationId: 'listShiftTypes', description: 'Return the shift types of the company and the generic ones.', summary: 'List shift types', security: [['bearerAuth' => []]], tags: ['Human Resources'], responses: [
        new OA\Response(response: 200, description: 'Shift types', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/HumanResourcesShiftType'))])),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
    ])]
    public function shiftTypes(): AnonymousResourceCollection
    {
        return HrRecordResource::collection(ShiftType::query()->orderBy('code')->gridPaginate(RequestedPerPage::resolve(200)));
    }

    /**
     * Create a shift type.
     */
    #[OA\Post(path: '/api/v1/hr/shift-types', operationId: 'storeShiftType', description: 'Create a working, absence or on-call shift type. Times are local to Europe/Zurich; an end at or before the start falls on the next day.', summary: 'Create shift type', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesSaveShiftTypeRequest')), tags: ['Human Resources'], responses: [
        new OA\Response(response: 201, description: 'Shift type', content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesShiftType')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function storeShiftType(SaveShiftTypeRequest $request): JsonResponse
    {
        return (new HrRecordResource(ShiftType::create($request->validated())))->response()->setStatusCode(201);
    }

    /**
     * Change a shift type.
     */
    #[OA\Put(path: '/api/v1/hr/shift-types/{id}', operationId: 'updateShiftType', description: 'Change a shift type of the company. Generic types are read-only. Existing shifts keep their instants.', summary: 'Update shift type', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesSaveShiftTypeRequest')), tags: ['Human Resources'], parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')], responses: [
        new OA\Response(response: 200, description: 'Shift type', content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesShiftType')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function updateShiftType(SaveShiftTypeRequest $request, string $id): HrRecordResource
    {
        $type = ShiftType::query()->findOrFail($id);
        $type->update($request->validated());

        return new HrRecordResource($type);
    }

    /**
     * List sequences.
     */
    #[OA\Get(path: '/api/v1/hr/shift-sequences', operationId: 'listShiftSequences', description: 'Return the shift sequences with their assignments.', summary: 'List shift sequences', security: [['bearerAuth' => []]], tags: ['Human Resources'], responses: [
        new OA\Response(response: 200, description: 'Sequences', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/HumanResourcesShiftSequence'))])),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
    ])]
    public function sequences(): AnonymousResourceCollection
    {
        return HrRecordResource::collection(ShiftSequence::query()->with('assignments')->orderBy('name')->gridPaginate(RequestedPerPage::resolve(200)));
    }

    /**
     * Create a sequence.
     */
    #[OA\Post(path: '/api/v1/hr/shift-sequences', operationId: 'storeShiftSequence', description: 'Create a rotation, a weekly pattern without Sundays or weekends, or a manual sequence. steps lists shift type ids; null is a free day.', summary: 'Create shift sequence', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesSaveShiftSequenceRequest')), tags: ['Human Resources'], responses: [
        new OA\Response(response: 201, description: 'Sequence', content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesShiftSequence')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function storeSequence(SaveShiftSequenceRequest $request): JsonResponse
    {
        return (new HrRecordResource(ShiftSequence::create($request->validated())))->response()->setStatusCode(201);
    }

    /**
     * Assign a sequence to an employee.
     */
    #[OA\Post(path: '/api/v1/hr/shift-sequences/assignments', operationId: 'assignShiftSequence', description: 'Make an employee follow a sequence for a period. offset_days starts the rotation further on.', summary: 'Assign shift sequence', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesAssignShiftSequenceRequest')), tags: ['Human Resources'], responses: [
        new OA\Response(response: 201, description: 'Assignment', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function assignSequence(AssignShiftSequenceRequest $request): JsonResponse
    {
        return (new HrRecordResource(ShiftSequenceAssignment::create($request->validated())))->response()->setStatusCode(201);
    }

    /**
     * Add an exception.
     */
    #[OA\Post(path: '/api/v1/hr/shift-exceptions', operationId: 'storeShiftException', description: 'Depart from the sequences on a date, for an employee, a department or the company. Without shift_type_id the day stays free. Applied at the next generation.', summary: 'Create shift exception', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesSaveShiftExceptionRequest')), tags: ['Human Resources'], responses: [
        new OA\Response(response: 201, description: 'Exception', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function storeException(SaveShiftExceptionRequest $request): JsonResponse
    {
        return (new HrRecordResource(ShiftException::create($request->validated())))->response()->setStatusCode(201);
    }

    /**
     * Set a staffing need.
     */
    #[OA\Post(path: '/api/v1/hr/staffing-needs', operationId: 'storeStaffingNeed', description: 'Set how many people a department needs on a shift type on weekdays, weekends or holidays.', summary: 'Create staffing need', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesSaveStaffingNeedRequest')), tags: ['Human Resources'], responses: [
        new OA\Response(response: 201, description: 'Staffing need', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function storeStaffingNeed(SaveStaffingNeedRequest $request): JsonResponse
    {
        return (new HrRecordResource(StaffingNeed::create($request->validated())))->response()->setStatusCode(201);
    }

    /**
     * Generate the drafts of a period.
     */
    #[OA\Post(path: '/api/v1/hr/shifts/generate', operationId: 'generateShifts', description: 'Create draft shifts from the sequences and exceptions. Replaces earlier generated drafts; manual and published shifts and days of approved leave stay.', summary: 'Generate shifts', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesShiftPeriodRequest')), tags: ['Human Resources'], responses: [
        new OA\Response(response: 200, description: 'Number of shifts created', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function generate(ShiftPeriodRequest $request): JsonResponse
    {
        $created = $this->planner->generate((string) $request->validated('from'), (string) $request->validated('to'), $request->validated('employee_ids'));

        return response()->json(['data' => ['created' => $created]]);
    }

    /**
     * Place one shift.
     */
    #[OA\Post(path: '/api/v1/hr/shifts', operationId: 'storeShift', description: 'Place a draft shift by hand.', summary: 'Create shift', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesSaveShiftRequest')), tags: ['Human Resources'], responses: [
        new OA\Response(response: 201, description: 'Shift', content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesShift')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function storeShift(SaveShiftRequest $request): JsonResponse
    {
        return (new HrRecordResource($this->planner->place($request->validated())))->response()->setStatusCode(201);
    }

    /**
     * Move a shift.
     */
    #[OA\Put(path: '/api/v1/hr/shifts/{id}', operationId: 'updateShift', description: 'Change a shift. A published shift goes back to draft and leaves the plan of the employee until the next publication.', summary: 'Update shift', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesSaveShiftRequest')), tags: ['Human Resources'], parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')], responses: [
        new OA\Response(response: 200, description: 'Shift', content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesShift')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function updateShift(SaveShiftRequest $request, string $id): HrRecordResource
    {
        return new HrRecordResource($this->planner->place($request->validated(), Shift::query()->findOrFail($id)));
    }

    /**
     * Remove a shift.
     */
    #[OA\Delete(path: '/api/v1/hr/shifts/{id}', operationId: 'destroyShift', description: 'Remove a shift from the plan.', summary: 'Delete shift', security: [['bearerAuth' => []]], tags: ['Human Resources'], parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')], responses: [
        new OA\Response(response: 204, description: 'Removed'),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
    ])]
    public function destroyShift(string $id): JsonResponse
    {
        Shift::query()->findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    /**
     * The board.
     */
    #[OA\Get(path: '/api/v1/hr/shifts/board', operationId: 'shiftBoard', description: 'Return days, employees with their draft and published shifts, staffing coverage against the needs, and the findings of the working time rules.', summary: 'Shift board', security: [['bearerAuth' => []]], tags: ['Human Resources'], parameters: [
        new OA\Parameter(ref: '#/components/parameters/HumanResourcesFrom'),
        new OA\Parameter(ref: '#/components/parameters/HumanResourcesTo'),
        new OA\Parameter(name: 'department_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'canton', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
    ], responses: [
        new OA\Response(response: 200, description: 'Board', content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesShiftBoard')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function board(ShiftPeriodRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->board->board(
            (string) $request->validated('from'),
            (string) $request->validated('to'),
            $request->validated('department_id'),
            $request->validated('canton'),
        )]);
    }

    /**
     * Publish the drafts of a period.
     */
    #[OA\Post(path: '/api/v1/hr/shifts/publish', operationId: 'publishShifts', description: 'Publish the drafts and mail each employee the own shifts. Blocking findings of the working time rules answer 422 unless acknowledgement_reason is given.', summary: 'Publish shifts', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesShiftPeriodRequest')), tags: ['Human Resources'], responses: [
        new OA\Response(response: 201, description: 'Publication', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function publish(ShiftPeriodRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $publication = $this->publications->publish(
            $actor,
            (string) $request->validated('from'),
            (string) $request->validated('to'),
            $request->validated('department_id'),
            $request->validated('acknowledgement_reason'),
        );

        return (new HrRecordResource($publication))->response()->setStatusCode(201);
    }

    /**
     * The absence plan.
     */
    #[OA\Get(path: '/api/v1/hr/absence-plan', operationId: 'absencePlan', description: 'Return approved leave and published absence shifts, day by day.', summary: 'Absence plan', security: [['bearerAuth' => []]], tags: ['Human Resources'], parameters: [
        new OA\Parameter(ref: '#/components/parameters/HumanResourcesFrom'),
        new OA\Parameter(ref: '#/components/parameters/HumanResourcesTo'),
        new OA\Parameter(name: 'department_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ], responses: [
        new OA\Response(response: 200, description: 'Absences', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function absencePlan(ShiftPeriodRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->board->absencePlan((string) $request->validated('from'), (string) $request->validated('to'), $request->validated('department_id'))]);
    }

    /**
     * The published shifts of the user.
     */
    #[OA\Get(path: '/api/v1/hr/time/my-shifts', operationId: 'myShifts', description: 'Employee self-service: the published shifts of the authenticated user. No module role is needed.', summary: 'My shifts', security: [['bearerAuth' => []]], tags: ['Human Resources'], parameters: [
        new OA\Parameter(ref: '#/components/parameters/HumanResourcesFrom'),
        new OA\Parameter(ref: '#/components/parameters/HumanResourcesTo'),
    ], responses: [
        new OA\Response(response: 200, description: 'Shifts', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function myShifts(ShiftPeriodRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $employeeIds = Employee::query()->where('user_id', $user->id)->pluck('id');

        $shifts = Shift::query()->with('type')->published()->whereIn('employee_id', $employeeIds)
            ->betweenDays((string) $request->validated('from'), (string) $request->validated('to'))
            ->orderBy('work_date')->get();

        return response()->json(['data' => $shifts->map(static fn (Shift $shift): array => ShiftBoardService::cell($shift))->all()]);
    }
}

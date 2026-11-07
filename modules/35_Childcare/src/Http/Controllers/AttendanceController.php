<?php

declare(strict_types=1);

namespace Modules\Childcare\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Childcare\Http\Requests\CareRequest;
use Modules\Childcare\Http\Resources\ChildcareResource;
use Modules\Childcare\Models\Attendance;
use Modules\Childcare\Models\AttendanceCorrection;
use Modules\Childcare\Models\Child;
use Modules\Childcare\Services\AttendanceService;
use OpenApi\Attributes as OA;

/**
 * Attendance from the tablet: check-in, check-out, absences, corrections by authorised staff and the monthly register.
 */
final class AttendanceController extends Controller
{
    public function __construct(private readonly AttendanceService $attendance) {}

    #[OA\Post(path: '/api/v1/childcare/attendances/check-in', operationId: 'checkInChildcareChild', description: 'Check a child in, optionally with the measured temperature.', summary: 'Check in', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ChildcareCheckInRequest')), tags: ['Childcare'], responses: [
        new OA\Response(response: 200, description: 'Checked in', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareRecordResponse')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ChildcareUnprocessable', response: 422),
    ])]
    public function checkIn(CareRequest $request): JsonResponse
    {
        $child = $this->child((string) $request->validated('child_id'));
        $attendance = $this->attendance->checkIn($child, $request->validated('at'), $request->validated('temperature'), $request->user()?->getKey());

        return (new ChildcareResource($attendance))->response()->setStatusCode(200);
    }

    #[OA\Post(path: '/api/v1/childcare/attendances/check-out', operationId: 'checkOutChildcareChild', description: 'Check a child out to an authorised reference person.', summary: 'Check out', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ChildcareCheckOutRequest')), tags: ['Childcare'], responses: [
        new OA\Response(response: 200, description: 'Checked out', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareRecordResponse')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ChildcareUnprocessable', response: 422),
    ])]
    public function checkOut(CareRequest $request): ChildcareResource
    {
        $child = $this->child((string) $request->validated('child_id'));

        return new ChildcareResource($this->attendance->checkOut($child, $request->validated('at'), (string) $request->validated('picked_up_by'), $request->user()?->getKey()));
    }

    #[OA\Post(path: '/api/v1/childcare/attendances/absence', operationId: 'markChildcareAbsence', description: 'Record that a child is absent on a day.', summary: 'Record absence', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ChildcareAbsenceRequest')), tags: ['Childcare'], responses: [
        new OA\Response(response: 200, description: 'Absence recorded', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareRecordResponse')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ChildcareUnprocessable', response: 422),
    ])]
    public function absence(CareRequest $request): JsonResponse
    {
        $child = $this->child((string) $request->validated('child_id'));
        $attendance = $this->attendance->markAbsent($child, (string) $request->validated('date'), (string) $request->validated('reason'));

        return (new ChildcareResource($attendance))->response()->setStatusCode(200);
    }

    #[OA\Put(path: '/api/v1/childcare/attendances/{id}', operationId: 'correctChildcareAttendance', description: 'Correct an attendance; each previous value is kept with the reason and the author. Management only.', summary: 'Correct attendance', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ChildcareCorrectionRequest')), tags: ['Childcare'], parameters: [new OA\Parameter(ref: '#/components/parameters/ChildcareId')], responses: [
        new OA\Response(response: 200, description: 'Attendance corrected', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareRecordResponse')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ChildcareNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ChildcareUnprocessable', response: 422),
    ])]
    public function correct(CareRequest $request, string $id): ChildcareResource
    {
        $attendance = Attendance::query()->findOrFail($id);
        $this->authorize('correct', $attendance);

        return new ChildcareResource($this->attendance->correct($attendance, $request->corrections(), (string) $request->validated('reason'), $request->user()?->getKey()));
    }

    #[OA\Get(path: '/api/v1/childcare/attendances/{id}/corrections', operationId: 'listChildcareAttendanceCorrections', description: 'Return the history of corrections of an attendance.', summary: 'List corrections', security: [['bearerAuth' => []]], tags: ['Childcare'], parameters: [new OA\Parameter(ref: '#/components/parameters/ChildcareId')], responses: [
        new OA\Response(response: 200, description: 'Corrections', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareRecordCollection')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ChildcareNotFound', response: 404),
    ])]
    public function corrections(string $id): AnonymousResourceCollection
    {
        $attendance = Attendance::query()->findOrFail($id);
        $this->authorize('correct', $attendance);

        return ChildcareResource::collection(AttendanceCorrection::query()->where('attendance_id', $attendance->id)->orderBy('created_at')->get());
    }

    #[OA\Get(path: '/api/v1/childcare/attendances/register', operationId: 'childcareMonthlyRegister', description: 'Return the monthly register: per child and day the status and the times.', summary: 'Monthly register', security: [['bearerAuth' => []]], tags: ['Childcare'], parameters: [
        new OA\Parameter(name: 'month', in: 'query', required: true, schema: new OA\Schema(type: 'string', example: '2026-11')),
        new OA\Parameter(name: 'group_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ], responses: [
        new OA\Response(response: 200, description: 'Register', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareDataResponse')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ChildcareUnprocessable', response: 422),
    ])]
    public function register(CareRequest $request): JsonResponse
    {
        $this->authorize('care', Child::class);

        return response()->json(['data' => $this->attendance->register((string) $request->validated('month'), $request->validated('group_id'))]);
    }

    private function child(string $id): Child
    {
        $child = Child::query()->findOrFail($id);
        $this->authorize('care', $child);

        return $child;
    }
}

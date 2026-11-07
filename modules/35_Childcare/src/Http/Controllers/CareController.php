<?php

declare(strict_types=1);

namespace Modules\Childcare\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Childcare\Http\Requests\CareRequest;
use Modules\Childcare\Http\Resources\ChildcareResource;
use Modules\Childcare\Models\Activity;
use Modules\Childcare\Models\ActivityEnrolment;
use Modules\Childcare\Models\Child;
use Modules\Childcare\Models\Message;
use Modules\Childcare\Models\Note;
use Modules\Childcare\Models\StaffShift;
use Modules\Childcare\Services\CareService;
use OpenApi\Attributes as OA;

/**
 * Notes and daily reports, meal counts, staff hours, extracurricular activities and messages to the families.
 */
final class CareController extends Controller
{
    public function __construct(private readonly CareService $care) {}

    #[OA\Get(path: '/api/v1/childcare/notes', operationId: 'listChildcareNotes', description: 'Return the notes about a child, optionally of a day.', summary: 'List notes', security: [['bearerAuth' => []]], tags: ['Childcare'], parameters: [
        new OA\Parameter(name: 'child_id', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
    ], responses: [
        new OA\Response(response: 200, description: 'Notes', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareRecordCollection')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ChildcareUnprocessable', response: 422),
    ])]
    public function notes(CareRequest $request): AnonymousResourceCollection
    {
        $child = $this->child((string) $request->validated('child_id'));

        return ChildcareResource::collection(Note::query()->where('child_id', $child->id)
            ->when($request->validated('date') !== null, fn ($query) => $query->whereDate('date', (string) $request->validated('date')))
            ->latest()->get());
    }

    #[OA\Post(path: '/api/v1/childcare/notes', operationId: 'storeChildcareNote', description: 'Record a note about a child; notes visible to the family appear in the daily report.', summary: 'Record note', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ChildcareNoteRequest')), tags: ['Childcare'], responses: [
        new OA\Response(response: 201, description: 'Note recorded', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareRecordResponse')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ChildcareUnprocessable', response: 422),
    ])]
    public function storeNote(CareRequest $request): JsonResponse
    {
        $this->child((string) $request->validated('child_id'));

        return $this->created(Note::query()->create([...$request->validated(), 'author_id' => $request->user()?->getKey()]));
    }

    #[OA\Get(path: '/api/v1/childcare/children/{id}/daily-report', operationId: 'showChildcareDailyReport', description: 'Return the daily report of a child as the family sees it.', summary: 'Daily report', security: [['bearerAuth' => []]], tags: ['Childcare'], parameters: [
        new OA\Parameter(ref: '#/components/parameters/ChildcareId'),
        new OA\Parameter(name: 'date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
    ], responses: [
        new OA\Response(response: 200, description: 'Daily report', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareDataResponse')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ChildcareNotFound', response: 404),
    ])]
    public function dailyReport(CareRequest $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->care->dailyReport($this->child($id), $request->string('date', now()->toDateString())->toString())]);
    }

    #[OA\Get(path: '/api/v1/childcare/meals', operationId: 'childcareMealCounts', description: 'Return the meals to cook on a day and the dietary requirements of the children expected.', summary: 'Meal counts', security: [['bearerAuth' => []]], tags: ['Childcare'], parameters: [
        new OA\Parameter(name: 'date', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
    ], responses: [
        new OA\Response(response: 200, description: 'Meal counts', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareDataResponse')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ChildcareUnprocessable', response: 422),
    ])]
    public function meals(CareRequest $request): JsonResponse
    {
        $this->authorize('care', Child::class);

        return response()->json(['data' => $this->care->mealCounts((string) $request->validated('date'))]);
    }

    #[OA\Post(path: '/api/v1/childcare/staff-shifts', operationId: 'storeChildcareStaffShift', description: 'Record the hours of a staff member; educators record their own hours only.', summary: 'Record staff hours', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ChildcareStaffShiftRequest')), tags: ['Childcare'], responses: [
        new OA\Response(response: 201, description: 'Shift recorded', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareRecordResponse')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ChildcareUnprocessable', response: 422),
    ])]
    public function storeShift(CareRequest $request): JsonResponse
    {
        $this->authorize((string) $request->validated('user_id') === (string) $request->user()?->getKey() ? 'care' : 'manageRecords', Child::class);

        return $this->created(StaffShift::query()->create($request->validated()));
    }

    #[OA\Get(path: '/api/v1/childcare/staff-hours', operationId: 'childcareStaffHours', description: 'Return the minutes worked per staff member in a month.', summary: 'Staff hours', security: [['bearerAuth' => []]], tags: ['Childcare'], parameters: [
        new OA\Parameter(name: 'month', in: 'query', required: true, schema: new OA\Schema(type: 'string', example: '2026-11')),
    ], responses: [
        new OA\Response(response: 200, description: 'Staff hours', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareDataResponse')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ChildcareUnprocessable', response: 422),
    ])]
    public function staffHours(CareRequest $request): JsonResponse
    {
        $this->authorize('manageRecords', Child::class);

        return response()->json(['data' => $this->care->staffHours((string) $request->validated('month'))]);
    }

    #[OA\Get(path: '/api/v1/childcare/activities', operationId: 'listChildcareActivities', description: 'Return the extracurricular activities with their enrolments.', summary: 'List activities', security: [['bearerAuth' => []]], tags: ['Childcare'], responses: [
        new OA\Response(response: 200, description: 'Activities', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareRecordCollection')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareForbidden', response: 403),
    ])]
    public function activities(): AnonymousResourceCollection
    {
        $this->authorize('care', Child::class);

        return ChildcareResource::collection(Activity::query()->with('enrolments')->orderBy('weekday')->orderBy('starts_at')->get());
    }

    #[OA\Post(path: '/api/v1/childcare/activities', operationId: 'storeChildcareActivity', description: 'Create an extracurricular activity, optionally with a monthly fee.', summary: 'Create activity', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ChildcareActivityRequest')), tags: ['Childcare'], responses: [
        new OA\Response(response: 201, description: 'Activity created', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareRecordResponse')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ChildcareUnprocessable', response: 422),
    ])]
    public function storeActivity(CareRequest $request): JsonResponse
    {
        $this->authorize('manageRecords', Child::class);

        return $this->created(Activity::query()->create($request->validated()));
    }

    #[OA\Post(path: '/api/v1/childcare/activities/{id}/enrolments', operationId: 'enrolChildcareActivity', description: 'Enrol a child in an activity, within its capacity.', summary: 'Enrol in activity', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ChildcareEnrolmentRequest')), tags: ['Childcare'], parameters: [new OA\Parameter(ref: '#/components/parameters/ChildcareId')], responses: [
        new OA\Response(response: 201, description: 'Child enrolled', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareRecordResponse')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ChildcareNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ChildcareUnprocessable', response: 422),
    ])]
    public function enrol(CareRequest $request, string $id): JsonResponse
    {
        $activity = Activity::query()->findOrFail($id);
        $this->authorize('manageRecords', $activity);
        $active = $activity->enrolments()->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', (string) $request->validated('valid_from')))->count();

        abort_if($activity->capacity > 0 && $active >= $activity->capacity, Response::HTTP_UNPROCESSABLE_ENTITY, 'The activity is full.');

        return $this->created(ActivityEnrolment::query()->create([...$request->validated(), 'activity_id' => $activity->id]));
    }

    #[OA\Get(path: '/api/v1/childcare/children/{id}/messages', operationId: 'listChildcareMessages', description: 'Return the messages exchanged with the family of a child.', summary: 'List messages', security: [['bearerAuth' => []]], tags: ['Childcare'], parameters: [new OA\Parameter(ref: '#/components/parameters/ChildcareId')], responses: [
        new OA\Response(response: 200, description: 'Messages', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareRecordCollection')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ChildcareNotFound', response: 404),
    ])]
    public function messages(string $id): AnonymousResourceCollection
    {
        $child = $this->child($id);
        Message::query()->where('child_id', $child->id)->where('direction', 'from_family')->whereNull('read_at')->update(['read_at' => now()]);

        return ChildcareResource::collection(Message::query()->where('child_id', $child->id)->orderBy('created_at')->get());
    }

    #[OA\Post(path: '/api/v1/childcare/children/{id}/messages', operationId: 'storeChildcareMessage', description: 'Send a message to the family of a child.', summary: 'Message the family', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ChildcareMessageRequest')), tags: ['Childcare'], parameters: [new OA\Parameter(ref: '#/components/parameters/ChildcareId')], responses: [
        new OA\Response(response: 201, description: 'Message sent', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareRecordResponse')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ChildcareNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ChildcareUnprocessable', response: 422),
    ])]
    public function storeMessage(CareRequest $request, string $id): JsonResponse
    {
        $child = $this->child($id);

        return $this->created(Message::query()->create(['child_id' => $child->id, 'direction' => 'to_family', 'body' => $request->validated('body'), 'sender_user_id' => $request->user()?->getKey()]));
    }

    private function child(string $id): Child
    {
        $child = Child::query()->findOrFail($id);
        $this->authorize('care', $child);

        return $child;
    }

    private function created(mixed $record): JsonResponse
    {
        return (new ChildcareResource($record))->response()->setStatusCode(Response::HTTP_CREATED);
    }
}

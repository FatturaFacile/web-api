<?php

declare(strict_types=1);

namespace Modules\Childcare\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Sales\DocumentNumberingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Childcare\Http\Requests\ChildRecordRequest;
use Modules\Childcare\Http\Resources\ChildcareResource;
use Modules\Childcare\Models\Child;
use Modules\Childcare\Models\ChildFee;
use Modules\Childcare\Models\Group;
use Modules\Childcare\Models\ReferencePerson;
use Modules\Childcare\Models\WeeklyPlan;
use Modules\Childcare\Services\EnrolmentService;
use Modules\Childcare\Services\FamilyPortalService;
use OpenApi\Attributes as OA;

/**
 * Children, their groups, reference persons, weekly plans and fee plans.
 */
final class ChildController extends Controller
{
    public function __construct(private readonly EnrolmentService $enrolments) {}

    /**
     * Return the groups of the kindergarten with the active children they hold and the places left.
     */
    #[OA\Get(path: '/api/v1/childcare/groups', operationId: 'listChildcareGroups', description: 'Return the groups of the kindergarten.', summary: 'List groups', security: [['bearerAuth' => []]], tags: ['Childcare'], responses: [
        new OA\Response(response: 200, description: 'Groups', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareRecordCollection')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareForbidden', response: 403),
    ])]
    public function groups(): AnonymousResourceCollection
    {
        $this->authorize('care', Child::class);

        return ChildcareResource::collection($this->enrolments->occupancy());
    }

    /**
     * Create a group or room.
     */
    #[OA\Post(path: '/api/v1/childcare/groups', operationId: 'storeChildcareGroup', description: 'Create a group or room.', summary: 'Create group', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ChildcareGroupRequest')), tags: ['Childcare'], responses: [
        new OA\Response(response: 201, description: 'Group created', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareRecordResponse')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ChildcareUnprocessable', response: 422),
    ])]
    public function storeGroup(ChildRecordRequest $request): JsonResponse
    {
        $this->authorize('manageRecords', Child::class);

        return $this->created(Group::query()->create($request->validated()));
    }

    /**
     * Return the children, optionally of a group or with a status, sorted by name.
     */
    #[OA\Get(path: '/api/v1/childcare/children', operationId: 'listChildcareChildren', description: 'Return the children, optionally of a group or with a status, sorted by name.', summary: 'List children', security: [['bearerAuth' => []]], tags: ['Childcare'], parameters: [
        new OA\Parameter(name: 'group_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['active', 'left', 'waiting'])),
    ], responses: [
        new OA\Response(response: 200, description: 'Children', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareRecordCollection')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('care', Child::class);

        $children = Child::query()
            ->when($request->filled('group_id'), fn ($query) => $query->where('group_id', $request->string('group_id')->toString()))
            ->where('status', $request->string('status', 'active')->toString())
            ->get()
            ->sortBy(static fn (Child $child): string => mb_strtolower("{$child->last_name} {$child->first_name}"))
            ->values();

        return ChildcareResource::collection($children);
    }

    /**
     * Enrol a child. Personal and health data is stored encrypted.
     */
    #[OA\Post(path: '/api/v1/childcare/children', operationId: 'storeChildcareChild', description: 'Enrol a child. Personal and health data is stored encrypted.', summary: 'Enrol child', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ChildcareChildRequest')), tags: ['Childcare'], responses: [
        new OA\Response(response: 201, description: 'Child enrolled', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareRecordResponse')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ChildcareUnprocessable', response: 422),
    ])]
    public function store(ChildRecordRequest $request, DocumentNumberingService $numbering): JsonResponse
    {
        $this->authorize('manageRecords', Child::class);

        return $this->created($this->enrolments->register($request->validated(), $numbering->next('childcare_child')['number']));
    }

    /**
     * Return a child with reference persons and weekly plans.
     */
    #[OA\Get(path: '/api/v1/childcare/children/{id}', operationId: 'showChildcareChild', description: 'Return a child with reference persons and weekly plans.', summary: 'Show child', security: [['bearerAuth' => []]], tags: ['Childcare'], parameters: [new OA\Parameter(ref: '#/components/parameters/ChildcareId')], responses: [
        new OA\Response(response: 200, description: 'Child', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareRecordResponse')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ChildcareNotFound', response: 404),
    ])]
    public function show(string $id): ChildcareResource
    {
        $child = Child::query()->findOrFail($id);
        $this->authorize('care', $child);

        return new ChildcareResource($child->load(['referencePersons', 'weeklyPlans']));
    }

    /**
     * Change the data of a child.
     */
    #[OA\Put(path: '/api/v1/childcare/children/{id}', operationId: 'updateChildcareChild', description: 'Change the data of a child.', summary: 'Change child', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ChildcareChildRequest')), tags: ['Childcare'], parameters: [new OA\Parameter(ref: '#/components/parameters/ChildcareId')], responses: [
        new OA\Response(response: 200, description: 'Child changed', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareRecordResponse')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ChildcareNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ChildcareUnprocessable', response: 422),
    ])]
    public function update(ChildRecordRequest $request, string $id): ChildcareResource
    {
        $child = Child::query()->findOrFail($id);
        $this->authorize('manageRecords', $child);

        return new ChildcareResource($this->enrolments->change($child, $request->validated()));
    }

    /**
     * Return the children waiting for a place, in the order of their applications.
     */
    #[OA\Get(path: '/api/v1/childcare/waiting-list', operationId: 'childcareWaitingList', description: 'Return the children waiting for a place, in the order of their applications (enrolled_on is the application date), each with its position.', summary: 'Waiting list', security: [['bearerAuth' => []]], tags: ['Childcare'], responses: [
        new OA\Response(response: 200, description: 'Waiting children', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareRecordCollection')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareForbidden', response: 403),
    ])]
    public function waitingList(): AnonymousResourceCollection
    {
        $this->authorize('manageRecords', Child::class);

        return ChildcareResource::collection($this->enrolments->waitingList());
    }

    /**
     * Admit a waiting child into a group with a free place.
     */
    #[OA\Post(path: '/api/v1/childcare/children/{id}/admit', operationId: 'admitChildcareChild', description: 'Admit a child from the waiting list into a group (its own or the one given). The group must have a free place; the child becomes active on the date given, today by default.', summary: 'Admit from waiting list', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/ChildcareAdmitRequest')), tags: ['Childcare'], parameters: [new OA\Parameter(ref: '#/components/parameters/ChildcareId')], responses: [
        new OA\Response(response: 200, description: 'Child admitted', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareRecordResponse')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ChildcareNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ChildcareUnprocessable', response: 422),
    ])]
    public function admit(ChildRecordRequest $request, string $id): ChildcareResource
    {
        $child = Child::query()->findOrFail($id);
        $this->authorize('manageRecords', $child);

        return new ChildcareResource($this->enrolments->admit($child, $request->validated('group_id'), $request->validated('enrolled_on')));
    }

    /**
     * Add a guardian, relative or authorised pick-up person.
     */
    #[OA\Post(path: '/api/v1/childcare/children/{id}/reference-persons', operationId: 'storeChildcareReferencePerson', description: 'Add a guardian, relative or authorised pick-up person.', summary: 'Add reference person', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ChildcareReferencePersonRequest')), tags: ['Childcare'], parameters: [new OA\Parameter(ref: '#/components/parameters/ChildcareId')], responses: [
        new OA\Response(response: 201, description: 'Reference person added', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareRecordResponse')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ChildcareNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ChildcareUnprocessable', response: 422),
    ])]
    public function storeReferencePerson(ChildRecordRequest $request, string $id): JsonResponse
    {
        $child = $this->child($id, 'manageRecords');

        return $this->created(ReferencePerson::query()->create([...$request->validated(), 'child_id' => $child->id]));
    }

    /**
     * Mail a personal link to the family portal to a guardian; earlier links stop working.
     */
    #[OA\Post(path: '/api/v1/childcare/reference-persons/{id}/portal-access', operationId: 'issueChildcareFamilyAccess', description: 'Mail a personal link to the family portal to a guardian; earlier links stop working.', summary: 'Issue family portal access', security: [['bearerAuth' => []]], tags: ['Childcare'], parameters: [new OA\Parameter(ref: '#/components/parameters/ChildcareId')], responses: [
        new OA\Response(response: 202, description: 'Link sent', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareMessageResponse')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ChildcareNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ChildcareUnprocessable', response: 422),
    ])]
    public function issuePortalAccess(string $id, FamilyPortalService $portal): JsonResponse
    {
        $person = ReferencePerson::query()->findOrFail($id);
        $this->authorize('manageRecords', $person);
        $portal->issueAccess($person);

        return response()->json(['data' => ['message' => 'Link sent']], Response::HTTP_ACCEPTED);
    }

    /**
     * Set the planned days, times and meals of a child from a date on.
     */
    #[OA\Post(path: '/api/v1/childcare/children/{id}/weekly-plans', operationId: 'storeChildcareWeeklyPlan', description: 'Set the planned days, times and meals of a child from a date on.', summary: 'Set weekly plan', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ChildcareWeeklyPlanRequest')), tags: ['Childcare'], parameters: [new OA\Parameter(ref: '#/components/parameters/ChildcareId')], responses: [
        new OA\Response(response: 201, description: 'Plan set', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareRecordResponse')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ChildcareNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ChildcareUnprocessable', response: 422),
    ])]
    public function storeWeeklyPlan(ChildRecordRequest $request, string $id): JsonResponse
    {
        $child = $this->child($id, 'care');

        return $this->created(WeeklyPlan::query()->create([...$request->validated(), 'child_id' => $child->id]));
    }

    /**
     * Assign a fee plan to a child for a period.
     */
    #[OA\Post(path: '/api/v1/childcare/children/{id}/fees', operationId: 'storeChildcareChildFee', description: 'Assign a fee plan to a child for a period.', summary: 'Assign fee plan', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ChildcareChildFeeRequest')), tags: ['Childcare'], parameters: [new OA\Parameter(ref: '#/components/parameters/ChildcareId')], responses: [
        new OA\Response(response: 201, description: 'Fee plan assigned', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareRecordResponse')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ChildcareNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ChildcareUnprocessable', response: 422),
    ])]
    public function storeFee(ChildRecordRequest $request, string $id): JsonResponse
    {
        $child = $this->child($id, 'bill');

        return $this->created(ChildFee::query()->create([...$request->validated(), 'child_id' => $child->id]));
    }

    private function child(string $id, string $ability): Child
    {
        $child = Child::query()->findOrFail($id);
        $this->authorize($ability, $child);

        return $child;
    }

    private function created(mixed $record): JsonResponse
    {
        return (new ChildcareResource($record))->response()->setStatusCode(Response::HTTP_CREATED);
    }
}

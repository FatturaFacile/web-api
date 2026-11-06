<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CRM\Contracts\ActivityServiceInterface;
use Modules\CRM\DTOs\CreateActivityDTO;
use Modules\CRM\Http\Requests\StoreActivityRequest;
use Modules\CRM\Http\Resources\ActivityResource;
use Modules\CRM\Models\Activity;
use OpenApi\Attributes as OA;

final class ActivityController extends Controller
{
    public function __construct(
        private readonly ActivityServiceInterface $activityService,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Returns the paginated list of activities of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/crm/activities',
        operationId: 'listCrmActivities',
        description: 'Returns the paginated list of activities of the active tenant. Results can be narrowed with the query parameters.',
        summary: 'List activities',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Activities'],
        parameters: [
            new OA\Parameter(name: 'type', in: 'query', required: false, description: 'Filter by type', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Filter by status', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of activities', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CRMActivityResource')), new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $query = Activity::where('tenant_id', $tenant->id);

        if ($request->has('type')) {
            $query->where('type', $request->query('type'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        $activities = $query->orderBy('due_date')->paginate(25);

        return response()->json([
            'data' => ActivityResource::collection($activities),
            'meta' => [
                'current_page' => $activities->currentPage(),
                'total' => $activities->total(),
            ],
        ]);
    }

    /**
     * Creates a new activity in the active tenant.
     */
    #[OA\Post(
        path: '/api/v1/crm/activities',
        operationId: 'createCrmActivity',
        description: 'Creates a new activity in the active tenant.',
        summary: 'Create activity',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Activities'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CRMStoreActivityRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Activity created', content: new OA\JsonContent(ref: '#/components/schemas/CRMActivityResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StoreActivityRequest $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $actorId = (string) ($request->user()?->id ?? 'system');

        $activity = $this->activityService->logActivity(new CreateActivityDTO(
            tenantId: (string) $tenant->id,
            companyId: (string) $tenant->id,
            subject: $request->input('subject'),
            type: $request->input('type'),
            priority: $request->input('priority', 'medium'),
            dueDate: $request->input('due_date'),
            assignedUserId: $request->input('assigned_user_id'),
            relatedType: $request->input('related_type'),
            relatedId: $request->input('related_id'),
            durationMinutes: $request->input('duration_minutes') ? (int) $request->input('duration_minutes') : null,
            location: $request->input('location'),
            notes: $request->input('notes'),
            metadata: $request->input('metadata', [])
        ), $actorId);

        return response()->json(new ActivityResource($activity), 201);
    }

    /**
     * Returns a single activity of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/crm/activities/{activity}',
        operationId: 'getCrmActivity',
        description: 'Returns a single activity of the active tenant.',
        summary: 'Get activity',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Activities'],
        parameters: [
            new OA\Parameter(name: 'activity', in: 'path', required: true, description: 'Identifier of the activity', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/CRMActivityResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $activity = Activity::where('tenant_id', $tenant->id)->findOrFail($id);

        return response()->json(new ActivityResource($activity));
    }

    /**
     * Marks an activity as completed, recording the optional outcome.
     */
    #[OA\Post(
        path: '/api/v1/crm/activities/{id}/complete',
        operationId: 'completeCrmActivity',
        description: 'Marks an activity as completed, recording the optional outcome.',
        summary: 'Complete activity',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Activities'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the activity', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'outcome')])),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/CRMActivityResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function complete(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $activity = Activity::where('tenant_id', $tenant->id)->findOrFail($id);
        $actorId = (string) ($request->user()?->id ?? 'system');

        $completed = $this->activityService->completeActivity(
            $activity,
            $request->input('outcome'),
            $actorId
        );

        return response()->json(new ActivityResource($completed));
    }

    /**
     * Returns the activity timeline of the given related record.
     */
    #[OA\Get(
        path: '/api/v1/crm/activities/timeline',
        operationId: 'getTimelineCrmActivity',
        description: 'Returns the activity timeline of the given related record.',
        summary: 'Get activity timeline',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Activities'],
        parameters: [
            new OA\Parameter(name: 'related_type', in: 'query', required: true, description: 'Filter by related type', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'related_id', in: 'query', required: true, description: 'Filter by related', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/CRMActivityResource'))),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function timeline(Request $request): JsonResponse
    {
        $request->validate([
            'related_type' => ['required', 'string'],
            'related_id' => ['required', 'uuid'],
        ]);

        $tenant = $this->tenantContext->getTenant();
        $timeline = $this->activityService->getTimeline(
            (string) $tenant->id,
            $request->query('related_type'),
            $request->query('related_id')
        );

        return response()->json(ActivityResource::collection($timeline));
    }
}

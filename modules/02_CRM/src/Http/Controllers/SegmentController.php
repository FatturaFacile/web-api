<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Services\Tenancy\TenantContext;
use App\Support\Http\RequestedPerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Modules\CRM\Http\Requests\SaveSegmentRequest;
use Modules\CRM\Http\Resources\ContactMasterDataResource;
use Modules\CRM\Http\Resources\LeadResource;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\Segment;
use Modules\CRM\Services\SegmentService;
use OpenApi\Attributes as OA;

/**
 * Marketing segments: saved, whitelisted filters over contacts or leads, evaluated on demand.
 */
final class SegmentController extends Controller
{
    public function __construct(
        private readonly SegmentService $segmentService,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Lists the segments of the tenant.
     */
    #[OA\Get(
        path: '/api/v1/crm/segments',
        operationId: 'listCrmSegments',
        description: 'Lists the marketing segments of the active tenant.',
        summary: 'List segments',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Marketing'],
        responses: [
            new OA\Response(response: 200, description: 'Segments', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CRMSegment'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(): JsonResponse
    {
        $segments = Segment::where('tenant_id', $this->tenantContext->getTenantId())->orderBy('name')->get();

        return response()->json(['data' => $segments->map(fn (Segment $segment): array => $this->present($segment))->values()]);
    }

    /**
     * Creates a segment.
     */
    #[OA\Post(
        path: '/api/v1/crm/segments',
        operationId: 'createCrmSegment',
        description: 'Creates a marketing segment over contacts or leads. Each filter is {field, operator, value} on a whitelisted field (operators eq, neq, contains, starts_with, in, gte, lte, is_null, not_null), or {field: "consent", channel, purpose} to keep only subjects with a current opt-in.',
        summary: 'Create segment',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Marketing'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CRMSaveSegmentRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Segment created', content: new OA\JsonContent(ref: '#/components/schemas/CRMSegment')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Read-only role', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(SaveSegmentRequest $request): JsonResponse
    {
        $segment = Segment::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->tenantContext->getTenantId(),
            'name' => $request->input('name'),
            'description' => $request->input('description'),
            'entity' => $request->input('entity'),
            'filters' => array_values((array) $request->input('filters')),
            'is_active' => $request->boolean('is_active', true),
            'created_by_user_id' => $request->user()?->id,
        ]);

        return response()->json($this->present($segment), 201);
    }

    /**
     * Returns a segment.
     */
    #[OA\Get(
        path: '/api/v1/crm/segments/{id}',
        operationId: 'getCrmSegment',
        description: 'Returns a marketing segment of the active tenant with its current member count.',
        summary: 'Get segment',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Marketing'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the segment', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Segment', content: new OA\JsonContent(ref: '#/components/schemas/CRMSegment')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $segment = Segment::where('tenant_id', $this->tenantContext->getTenantId())->findOrFail($id);

        return response()->json([...$this->present($segment), 'member_count' => $this->segmentService->query($segment)->count()]);
    }

    /**
     * Updates a segment.
     */
    #[OA\Put(
        path: '/api/v1/crm/segments/{id}',
        operationId: 'updateCrmSegment',
        description: 'Updates the name, description, filters or activation of a segment. The entity cannot change. Also accepts the PATCH method.',
        summary: 'Update segment',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Marketing'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the segment', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CRMSaveSegmentRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Segment updated', content: new OA\JsonContent(ref: '#/components/schemas/CRMSegment')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function update(SaveSegmentRequest $request, string $id): JsonResponse
    {
        $segment = Segment::where('tenant_id', $this->tenantContext->getTenantId())->findOrFail($id);
        $changes = $request->safe()->only(['name', 'description', 'is_active']);
        if ($request->has('filters')) {
            $changes['filters'] = array_values((array) $request->input('filters'));
        }
        $segment->update($changes);

        return response()->json($this->present($segment));
    }

    /**
     * Deletes a segment.
     */
    #[OA\Delete(
        path: '/api/v1/crm/segments/{id}',
        operationId: 'deleteCrmSegment',
        description: 'Deletes a segment. Campaign members already added from it are kept.',
        summary: 'Delete segment',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Marketing'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the segment', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        Segment::where('tenant_id', $this->tenantContext->getTenantId())->findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    /**
     * Previews the current members of a segment.
     */
    #[OA\Get(
        path: '/api/v1/crm/segments/{id}/members',
        operationId: 'listCrmSegmentMembers',
        description: 'Returns the contacts or leads currently matching the segment, paginated.',
        summary: 'Preview segment members',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Marketing'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the segment', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Members', content: new OA\JsonContent(properties: [new OA\Property(property: 'entity', type: 'string'), new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')), new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function members(string $id): JsonResponse
    {
        $segment = Segment::where('tenant_id', $this->tenantContext->getTenantId())->findOrFail($id);
        $page = $this->segmentService->query($segment)->orderBy('created_at')->gridPaginate(RequestedPerPage::resolve(50));

        return response()->json([
            'entity' => $segment->entity,
            'data' => $page->getCollection()->map(fn (Contact|Lead $record): array => $record instanceof Contact
                ? (new ContactMasterDataResource($record))->resolve()
                : (new LeadResource($record))->resolve())->values(),
            'meta' => ['current_page' => $page->currentPage(), 'total' => $page->total()],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Segment $segment): array
    {
        return [
            'id' => (string) $segment->id,
            'name' => $segment->name,
            'description' => $segment->description,
            'entity' => $segment->entity,
            'filters' => $segment->filters ?? [],
            'is_active' => $segment->is_active,
            'created_at' => $segment->created_at?->toIso8601String(),
            'updated_at' => $segment->updated_at?->toIso8601String(),
        ];
    }
}

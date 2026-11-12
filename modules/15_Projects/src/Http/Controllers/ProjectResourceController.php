<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Projects\Http\Controllers\Concerns\PaginatesProjectRecords;
use Modules\Projects\Http\Requests\CapacityQueryRequest;
use Modules\Projects\Http\Requests\StoreRateCardRequest;
use Modules\Projects\Http\Requests\UpsertPersonResourceRequest;
use Modules\Projects\Http\Resources\PersonResourceResource;
use Modules\Projects\Http\Resources\ProjectRateCardResource;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectRateCard;
use Modules\Projects\Models\ProjectResource;
use Modules\Projects\Policies\ProjectPolicy;
use Modules\Projects\Services\ResourceCapacityService;
use Modules\Projects\Support\Money;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * People and rates across projects: weekly capacity and load, default rates of each person, and rate cards. Rates are
 * company-wide management data, so changing them is reserved to the company managers.
 */
final class ProjectResourceController extends Controller
{
    use PaginatesProjectRecords;

    public function __construct(
        private readonly ResourceCapacityService $capacity,
        private readonly ProjectPolicy $policy,
    ) {}

    /**
     * Weekly planned load against capacity per person, with over-allocated weeks flagged.
     */
    #[OA\Get(path: '/api/v1/projects/resource-capacity', operationId: 'projectsResourceCapacity', summary: 'Resource capacity and load', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date'), description: 'At most 26 weeks after from.'),
            new OA\Parameter(name: 'user_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Capacity report', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsCapacityReport')])),
            new OA\Response(response: 422, ref: '#/components/responses/ProjectsValidationError'),
        ])]
    public function capacity(CapacityQueryRequest $request): JsonResponse
    {
        $data = $request->validated();

        return response()->json(['data' => $this->capacity->weeklyLoad(CarbonImmutable::parse($data['from']), CarbonImmutable::parse($data['to']), $data['user_id'] ?? null)]);
    }

    /**
     * People with their default rates and weekly capacity.
     */
    #[OA\Get(path: '/api/v1/projects/resources', operationId: 'projectsResourcesList', summary: 'List project resources', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsPerPage'), new OA\Parameter(ref: '#/components/parameters/ProjectsPage')],
        responses: [
            new OA\Response(response: 200, description: 'Resources', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProjectsPersonResource')), new OA\Property(property: 'meta', ref: '#/components/schemas/ProjectsPaginationMeta')])),
        ])]
    public function index(Request $request): JsonResponse
    {
        return $this->paginated(ProjectResource::query()->with('capacityProfile')->orderBy('name')->paginate($this->perPage($request, 50)), PersonResourceResource::class);
    }

    /**
     * Sets the default rates and the weekly capacity of a member of the company.
     */
    #[OA\Put(path: '/api/v1/projects/resources/{userId}', operationId: 'projectsResourcesUpsert', summary: 'Set rates and capacity of a person', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsUserId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProjectsUpsertPersonResourceRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Resource saved', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsPersonResource')])),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 422, ref: '#/components/responses/ProjectsValidationError'),
        ])]
    public function upsert(string $userId, UpsertPersonResourceRequest $request, TenantContext $tenantContext): JsonResponse
    {
        $this->assertManager($request);
        $tenantId = (string) $tenantContext->getTenantId();
        $user = User::query()->whereKey($userId)->whereHas('tenants', fn ($query) => $query->where('tenants.id', $tenantId))->firstOrFail();

        return response()->json(['data' => new PersonResourceResource($this->capacity->upsertPersonResource($tenantId, $user, $request->validated()))]);
    }

    /**
     * Rate cards of the company, optionally of one project or person.
     */
    #[OA\Get(path: '/api/v1/projects/rate-cards', operationId: 'projectsRateCardsList', summary: 'List rate cards', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'project_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'user_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(ref: '#/components/parameters/ProjectsPerPage'),
            new OA\Parameter(ref: '#/components/parameters/ProjectsPage'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Rate cards', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProjectsRateCard')), new OA\Property(property: 'meta', ref: '#/components/schemas/ProjectsPaginationMeta')])),
        ])]
    public function rateCards(Request $request): JsonResponse
    {
        $cards = ProjectRateCard::query()
            ->when($request->query('project_id'), fn ($query, $projectId) => $query->where('project_id', $projectId))
            ->when($request->query('user_id'), fn ($query, $userId) => $query->where('user_id', $userId))
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request, 50));

        return $this->paginated($cards, ProjectRateCardResource::class);
    }

    /**
     * Adds a rate card for a person or a role, optionally limited to one project.
     */
    #[OA\Post(path: '/api/v1/projects/rate-cards', operationId: 'projectsRateCardsCreate', summary: 'Create rate card', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProjectsStoreRateCardRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Rate card created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsRateCard')])),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 422, ref: '#/components/responses/ProjectsValidationError'),
        ])]
    public function storeRateCard(StoreRateCardRequest $request, TenantContext $tenantContext): JsonResponse
    {
        $data = $request->validated();

        if (! empty($data['project_id'])) {
            $this->authorize('manage', Project::findOrFail($data['project_id']));
        } else {
            $this->assertManager($request);
        }

        $overlap = ProjectRateCard::query()
            ->where('project_id', $data['project_id'] ?? null)
            ->where('user_id', $data['user_id'] ?? null)
            ->where('role', ! empty($data['user_id']) ? null : ($data['role'] ?? null))
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $data['valid_from'] ?? '0001-01-01'))
            ->where(fn ($query) => $query->whereNull('valid_from')->orWhereDate('valid_from', '<=', $data['valid_to'] ?? '9999-12-31'))
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages(['valid_from' => __('projects_errors.rate_card.overlaps')]);
        }

        $card = ProjectRateCard::create([
            'tenant_id' => (string) $tenantContext->getTenantId(),
            'project_id' => $data['project_id'] ?? null,
            'user_id' => $data['user_id'] ?? null,
            'role' => ! empty($data['user_id']) ? null : ($data['role'] ?? null),
            'cost_rate' => Money::amount($data['cost_rate']),
            'bill_rate' => Money::amount($data['bill_rate']),
            'valid_from' => $data['valid_from'] ?? null,
            'valid_to' => $data['valid_to'] ?? null,
        ]);

        return response()->json(['data' => new ProjectRateCardResource($card)], 201);
    }

    /**
     * Removes a rate card; timesheets already logged keep the rates they were costed with.
     */
    #[OA\Delete(path: '/api/v1/projects/rate-cards/{rateCardId}', operationId: 'projectsRateCardsDelete', summary: 'Delete rate card', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsRateCardId')],
        responses: [
            new OA\Response(response: 200, description: 'Rate card deleted', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
        ])]
    public function destroyRateCard(string $rateCardId, Request $request): JsonResponse
    {
        $card = ProjectRateCard::findOrFail($rateCardId);

        if ($card->project_id !== null) {
            $this->authorize('manage', Project::findOrFail($card->project_id));
        } else {
            $this->assertManager($request);
        }

        $card->delete();

        return response()->json(['message' => 'Rate card deleted']);
    }

    private function assertManager(Request $request): void
    {
        $user = $request->user();

        if (! $user instanceof User || ! $this->policy->isManager($user)) {
            throw new AccessDeniedHttpException(__('projects_errors.rate_card.managers_only'));
        }
    }
}

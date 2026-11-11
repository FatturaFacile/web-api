<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\GeneralLedger\Enums\ConsolidationMethod;
use Modules\GeneralLedger\Enums\IntercompanyMappingType;
use Modules\GeneralLedger\Http\Requests\StoreConsolidationGroupRequest;
use Modules\GeneralLedger\Http\Requests\StoreConsolidationMemberRequest;
use Modules\GeneralLedger\Http\Requests\StoreIntercompanyMappingRequest;
use Modules\GeneralLedger\Http\Requests\StoreIntercompanyRelationRequest;
use Modules\GeneralLedger\Http\Requests\UpdateConsolidationGroupRequest;
use Modules\GeneralLedger\Http\Resources\ConsolidationGroupResource;
use Modules\GeneralLedger\Http\Resources\IntercompanyRelationResource;
use Modules\GeneralLedger\Models\ConsolidationGroup;
use Modules\GeneralLedger\Models\ConsolidationMember;
use Modules\GeneralLedger\Models\IntercompanyAccountMapping;
use Modules\GeneralLedger\Models\IntercompanyRelation;
use Modules\GeneralLedger\Services\Consolidation\ConsolidationGroupService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Configuration of the consolidation groups of the active tenant (the parent entity): members and their ownership,
 * intercompany relations and the mapping of intercompany accounts. Runs and reports are in ConsolidationRunController.
 */
final class ConsolidationGroupController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ConsolidationGroupService $groups,
        private readonly TenantContext $tenantContext,
    ) {}

    #[OA\Get(
        path: '/api/v1/general-ledger/consolidation/groups',
        operationId: 'listConsolidationGroups',
        description: 'Consolidation groups owned by the active tenant, with their members.',
        summary: 'List consolidation groups',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Consolidation'],
        responses: [
            new OA\Response(response: 200, description: 'Groups', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GeneralLedgerConsolidationGroupResource'))])),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(): JsonResponse
    {
        return $this->successResponse(ConsolidationGroupResource::collection(ConsolidationGroup::query()->with('members.memberTenant')->orderBy('name')->get()));
    }

    #[OA\Post(
        path: '/api/v1/general-ledger/consolidation/groups',
        operationId: 'createConsolidationGroup',
        description: 'Creates a group whose parent entity is the active tenant. The parent is added as the first member, fully consolidated at 100 %.',
        summary: 'Create consolidation group',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GeneralLedgerStoreConsolidationGroupRequest')),
        tags: ['General Ledger Consolidation'],
        responses: [
            new OA\Response(response: 201, description: 'Group created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerConsolidationGroupResource')])),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StoreConsolidationGroupRequest $request): JsonResponse
    {
        $group = $this->groups->createGroup((string) $this->tenantContext->getTenantId(), $request->validated());

        return $this->successResponse(new ConsolidationGroupResource($group->load('members.memberTenant')), 'Consolidation group created', Response::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/api/v1/general-ledger/consolidation/groups/{id}',
        operationId: 'getConsolidationGroup',
        description: 'A group with its members, intercompany relations and account mappings.',
        summary: 'Show consolidation group',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Consolidation'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Group', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerConsolidationGroupResource')])),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        return $this->successResponse(new ConsolidationGroupResource(ConsolidationGroup::query()->with(['members.memberTenant', 'relations.mappings'])->findOrFail($id)));
    }

    #[OA\Put(
        path: '/api/v1/general-ledger/consolidation/groups/{id}',
        operationId: 'updateConsolidationGroup',
        description: 'Changes the name, the group currency or the codes of the synthetic group accounts. Runs already made keep the values they were computed with.',
        summary: 'Update consolidation group',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GeneralLedgerUpdateConsolidationGroupRequest')),
        tags: ['General Ledger Consolidation'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Group updated', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerConsolidationGroupResource')])),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function update(UpdateConsolidationGroupRequest $request, string $id): JsonResponse
    {
        $group = $this->groups->updateGroup(ConsolidationGroup::query()->findOrFail($id), $request->validated());

        return $this->successResponse(new ConsolidationGroupResource($group->load('members.memberTenant')), 'Consolidation group updated');
    }

    #[OA\Delete(
        path: '/api/v1/general-ledger/consolidation/groups/{id}',
        operationId: 'deleteConsolidationGroup',
        description: 'Deletes a group with its members, relations and mappings. A group that has runs answers 409: delete the runs first.',
        summary: 'Delete consolidation group',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Consolidation'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Group deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'The group has runs', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        $this->groups->deleteGroup(ConsolidationGroup::query()->findOrFail($id));

        return $this->successResponse(null, 'Consolidation group deleted');
    }

    #[OA\Post(
        path: '/api/v1/general-ledger/consolidation/groups/{id}/members',
        operationId: 'addConsolidationMember',
        description: 'Adds another entity (tenant) to the group with the share the group owns and the consolidation method. The user must be able to read the ledger of that entity, otherwise 403. Proportional is stored but a run that includes it is refused.',
        summary: 'Add consolidation member',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GeneralLedgerStoreConsolidationMemberRequest')),
        tags: ['General Ledger Consolidation'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 201, description: 'Member added', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerConsolidationGroupResource')])),
            new OA\Response(response: 403, description: 'The ledger of the entity is not accessible', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Already a member', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function addMember(StoreConsolidationMemberRequest $request, string $id): JsonResponse
    {
        $group = ConsolidationGroup::query()->findOrFail($id);
        $this->groups->addMember($request->user(), $group, $request->validated('member_tenant_id'), (string) $request->validated('ownership_percentage'), ConsolidationMethod::from($request->validated('consolidation_method')), $request->validated('holder_tenant_id'), (int) ($request->validated('fiscal_year_offset_months') ?? 0), $request->validated('tax_rate') === null ? null : (string) $request->validated('tax_rate'), (string) ($request->validated('consolidation_basis') ?? 'own_year'));

        return $this->successResponse(new ConsolidationGroupResource($group->load('members.memberTenant')), 'Member added', Response::HTTP_CREATED);
    }

    #[OA\Put(
        path: '/api/v1/general-ledger/consolidation/groups/{id}/members/{memberId}',
        operationId: 'updateConsolidationMember',
        description: 'Changes the ownership percentage and the method of a member. The parent entity cannot be changed.',
        summary: 'Update consolidation member',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GeneralLedgerUpdateConsolidationMemberRequest')),
        tags: ['General Ledger Consolidation'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'memberId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Member updated', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerConsolidationGroupResource')])),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error or parent entity', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function updateMember(StoreConsolidationMemberRequest $request, string $id, string $memberId): JsonResponse
    {
        $group = ConsolidationGroup::query()->findOrFail($id);
        $member = ConsolidationMember::query()->where('group_id', $group->id)->findOrFail($memberId);
        $this->groups->updateMember($member, (string) $request->validated('ownership_percentage'), ConsolidationMethod::from($request->validated('consolidation_method')), $request->validated('holder_tenant_id'), $request->validated('fiscal_year_offset_months') === null ? null : (int) $request->validated('fiscal_year_offset_months'), $request->has('holder_tenant_id'), $request->validated('tax_rate') === null ? null : (string) $request->validated('tax_rate'), $request->has('tax_rate'), $request->validated('consolidation_basis'));

        return $this->successResponse(new ConsolidationGroupResource($group->load('members.memberTenant')), 'Member updated');
    }

    #[OA\Delete(
        path: '/api/v1/general-ledger/consolidation/groups/{id}/members/{memberId}',
        operationId: 'removeConsolidationMember',
        description: 'Removes a member that no intercompany relation uses. The parent entity cannot be removed.',
        summary: 'Remove consolidation member',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Consolidation'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'memberId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Member removed', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'The member has intercompany relations', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Parent entity', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function removeMember(string $id, string $memberId): JsonResponse
    {
        $group = ConsolidationGroup::query()->findOrFail($id);
        $this->groups->removeMember(ConsolidationMember::query()->where('group_id', $group->id)->findOrFail($memberId));

        return $this->successResponse(null, 'Member removed');
    }

    #[OA\Post(
        path: '/api/v1/general-ledger/consolidation/groups/{id}/relations',
        operationId: 'createIntercompanyRelation',
        description: 'Declares that two members of the group trade with or finance each other. `tolerance_amount` (default 0.01, in group currency) is the difference the reconciliation accepts as a match.',
        summary: 'Create intercompany relation',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GeneralLedgerStoreIntercompanyRelationRequest')),
        tags: ['General Ledger Consolidation'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 201, description: 'Relation created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerIntercompanyRelationResource')])),
            new OA\Response(response: 403, description: 'The ledger of an entity is not accessible', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'The pair already has a relation', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function storeRelation(StoreIntercompanyRelationRequest $request, string $id): JsonResponse
    {
        $group = ConsolidationGroup::query()->findOrFail($id);
        $relation = $this->groups->createRelation(
            $request->user(),
            $group,
            $request->validated('entity_a_tenant_id'),
            $request->validated('entity_b_tenant_id'),
            (string) ($request->validated('tolerance_amount') ?? '0.0100'),
            $request->validated('description'),
        );

        return $this->successResponse(new IntercompanyRelationResource($relation->load('mappings')), 'Intercompany relation created', Response::HTTP_CREATED);
    }

    #[OA\Delete(
        path: '/api/v1/general-ledger/consolidation/relations/{relationId}',
        operationId: 'deleteIntercompanyRelation',
        description: 'Deletes a relation with its account mappings. Runs already made keep the mappings they used.',
        summary: 'Delete intercompany relation',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Consolidation'],
        parameters: [new OA\Parameter(name: 'relationId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Relation deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroyRelation(string $relationId): JsonResponse
    {
        $this->groups->deleteRelation(IntercompanyRelation::query()->findOrFail($relationId));

        return $this->successResponse(null, 'Intercompany relation deleted');
    }

    #[OA\Post(
        path: '/api/v1/general-ledger/consolidation/relations/{relationId}/mappings',
        operationId: 'createIntercompanyMapping',
        description: 'Maps an account of entity A to an account of entity B of the relation: receivable/payable, revenue/cost, or investment/equity (A is the investor). Each account must belong to its own entity and to the type the mapping needs, otherwise 422.',
        summary: 'Map intercompany accounts',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GeneralLedgerStoreIntercompanyMappingRequest')),
        tags: ['General Ledger Consolidation'],
        parameters: [new OA\Parameter(name: 'relationId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 201, description: 'Mapping created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerIntercompanyRelationResource')])),
            new OA\Response(response: 403, description: 'The ledger of an entity is not accessible', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'The mapping already exists', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function storeMapping(StoreIntercompanyMappingRequest $request, string $relationId): JsonResponse
    {
        $relation = IntercompanyRelation::query()->findOrFail($relationId);
        $this->groups->addMapping($request->user(), $relation, IntercompanyMappingType::from($request->validated('mapping_type')), $request->validated('account_a_id'), $request->validated('account_b_id'));

        return $this->successResponse(new IntercompanyRelationResource($relation->load('mappings')), 'Mapping created', Response::HTTP_CREATED);
    }

    #[OA\Delete(
        path: '/api/v1/general-ledger/consolidation/mappings/{mappingId}',
        operationId: 'deleteIntercompanyMapping',
        description: 'Deletes an account mapping.',
        summary: 'Delete intercompany mapping',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Consolidation'],
        parameters: [new OA\Parameter(name: 'mappingId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Mapping deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroyMapping(Request $request, string $mappingId): JsonResponse
    {
        $this->groups->deleteMapping(IntercompanyAccountMapping::query()->findOrFail($mappingId));

        return $this->successResponse(null, 'Mapping deleted');
    }
}

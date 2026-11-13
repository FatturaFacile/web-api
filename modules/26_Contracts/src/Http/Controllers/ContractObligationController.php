<?php

declare(strict_types=1);

namespace Modules\Contracts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Contracts\Enums\ContractKind;
use Modules\Contracts\Http\Requests\SaveContractObligationRequest;
use Modules\Contracts\Http\Resources\ContractObligationResource;
use Modules\Contracts\Models\ContractObligation;
use Modules\Contracts\Services\ContractObligationService;
use Modules\Contracts\Services\ContractRegister;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Obligations and milestones of contracts, with their reminders.
 */
final class ContractObligationController extends Controller
{
    use ApiResponse;

    /**
     * Inject the collaborators.
     */
    public function __construct(
        private readonly ContractRegister $register,
        private readonly ContractObligationService $obligations,
        private readonly TenantClock $clock,
    ) {}

    /**
     * Obligations of the company.
     */
    #[OA\Get(
        path: '/api/v1/contract-register/obligations',
        operationId: 'listContractObligations',
        description: 'Obligations and milestones of every contract of the company, soonest due first. Use `due_within_days` for what is coming up and `overdue` for what is late. The reminder is raised by the daily job once an item comes within its `remind_days_before`.',
        summary: 'List obligations and milestones',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Contract Obligations'],
        parameters: [
            new OA\Parameter(name: 'contract_kind', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['customer', 'supplier'])),
            new OA\Parameter(name: 'contract_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['obligation', 'milestone'])),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['open', 'completed', 'waived'])),
            new OA\Parameter(name: 'owner_user_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'due_within_days', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 0, maximum: 3650)),
            new OA\Parameter(name: 'overdue', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100, minimum: 5)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Obligations', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ContractObligationResource')), new OA\Property(property: 'meta', type: 'object')])),
            new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ContractUnprocessable', response: 422),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'contract_kind' => ['nullable', 'in:customer,supplier'],
            'contract_id' => ['nullable', 'uuid'],
            'type' => ['nullable', 'in:obligation,milestone'],
            'status' => ['nullable', 'in:open,completed,waived'],
            'owner_user_id' => ['nullable', 'uuid'],
            'due_within_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'overdue' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $page = $this->obligations->query($filters, $this->clock->today())->gridPaginate((int) ($filters['per_page'] ?? 25));

        return $this->successResponse(
            ContractObligationResource::collection($page->getCollection()),
            'Obligations',
            meta: ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]
        );
    }

    /**
     * Obligations of one contract.
     */
    #[OA\Get(
        path: '/api/v1/contract-register/{kind}/{id}/obligations',
        operationId: 'listContractObligationsOfContract',
        description: 'Obligations and milestones of one contract, soonest due first.',
        summary: 'List the obligations of a contract',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Contract Obligations'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ContractKindPath'), new OA\Parameter(ref: '#/components/parameters/ContractIdPath')],
        responses: [
            new OA\Response(response: 200, description: 'Obligations', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ContractObligationResource'))])),
            new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ContractNotFound', response: 404),
        ]
    )]
    public function forContract(string $kind, string $id): JsonResponse
    {
        $contractKind = ContractKind::from($kind);
        $contract = $this->register->find($contractKind, $id);

        return $this->successResponse(ContractObligationResource::collection(
            $this->obligations->query(['contract_kind' => $contractKind->value, 'contract_id' => (string) $contract->getKey()], $this->clock->today())->get()
        ), 'Obligations');
    }

    /**
     * Add an obligation.
     */
    #[OA\Post(
        path: '/api/v1/contract-register/{kind}/{id}/obligations',
        operationId: 'createContractObligation',
        description: 'Adds an obligation or a milestone to a contract in force or being prepared. A milestone can carry an amount with its currency. Terminated contracts take no new items.',
        summary: 'Add an obligation or milestone',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ContractSaveObligationRequest')),
        tags: ['Contract Obligations'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ContractKindPath'), new OA\Parameter(ref: '#/components/parameters/ContractIdPath')],
        responses: [
            new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ContractObligationResource')])),
            new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ContractNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/ContractConflict', response: 409),
            new OA\Response(ref: '#/components/responses/ContractUnprocessable', response: 422),
        ]
    )]
    public function store(SaveContractObligationRequest $request, string $kind, string $id): JsonResponse
    {
        $contractKind = ContractKind::from($kind);
        $contract = $this->register->find($contractKind, $id);

        if (in_array($contract->getAttribute('status'), ['terminated', 'cancelled'], true)) {
            abort(Response::HTTP_CONFLICT, 'A terminated contract takes no new obligations.');
        }

        $obligation = ContractObligation::create([
            ...$request->validated(),
            'contract_kind' => $contractKind->value,
            'contract_id' => $contract->getKey(),
            'responsible_party' => $request->validated('responsible_party') ?? 'own',
            'remind_days_before' => $request->validated('remind_days_before') ?? 7,
        ]);

        return $this->successResponse(new ContractObligationResource($obligation->refresh()), 'Obligation added', Response::HTTP_CREATED);
    }

    /**
     * Change an obligation.
     */
    #[OA\Put(
        path: '/api/v1/contract-register/obligations/{obligationId}',
        operationId: 'updateContractObligation',
        description: 'Changes an open obligation or milestone. Moving the due date or the reminder window re-arms the reminder. A closed item cannot change.',
        summary: 'Update an obligation or milestone',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ContractSaveObligationRequest')),
        tags: ['Contract Obligations'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ContractObligationIdPath')],
        responses: [
            new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ContractObligationResource')])),
            new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ContractNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/ContractConflict', response: 409),
            new OA\Response(ref: '#/components/responses/ContractUnprocessable', response: 422),
        ]
    )]
    public function update(SaveContractObligationRequest $request, string $obligationId): JsonResponse
    {
        $obligation = ContractObligation::query()->findOrFail($obligationId);

        if ($obligation->status !== 'open') {
            abort(Response::HTTP_CONFLICT, "A {$obligation->status} item cannot change.");
        }

        $data = $request->validated();
        $rearm = $obligation->due_date->toDateString() !== $data['due_date'] || (int) $obligation->remind_days_before !== (int) ($data['remind_days_before'] ?? $obligation->remind_days_before);
        $obligation->update([...$data, 'responsible_party' => $data['responsible_party'] ?? $obligation->responsible_party, 'remind_days_before' => $data['remind_days_before'] ?? $obligation->remind_days_before, ...($rearm ? ['reminded_at' => null] : [])]);

        return $this->successResponse(new ContractObligationResource($obligation->refresh()), 'Obligation updated');
    }

    /**
     * Delete an obligation.
     */
    #[OA\Delete(
        path: '/api/v1/contract-register/obligations/{obligationId}',
        operationId: 'deleteContractObligation',
        description: 'Removes an obligation or milestone that was entered by mistake. Use complete or waive to close a real one: those stay on record.',
        summary: 'Delete an obligation or milestone',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Contract Obligations'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ContractObligationIdPath')],
        responses: [
            new OA\Response(response: 200, description: 'Deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ContractNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/ContractConflict', response: 409),
        ]
    )]
    public function destroy(string $obligationId): JsonResponse
    {
        $obligation = ContractObligation::query()->findOrFail($obligationId);

        if ($obligation->status !== 'open') {
            abort(Response::HTTP_CONFLICT, 'A closed item stays on record.');
        }

        $obligation->delete();

        return $this->successResponse(null, 'Obligation deleted');
    }

    /**
     * Complete an obligation.
     */
    #[OA\Post(
        path: '/api/v1/contract-register/obligations/{obligationId}/complete',
        operationId: 'completeContractObligation',
        description: 'Marks an open obligation or milestone as done, with an optional note. It stays on record.',
        summary: 'Complete an obligation or milestone',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/ContractCloseObligationRequest')),
        tags: ['Contract Obligations'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ContractObligationIdPath')],
        responses: [
            new OA\Response(response: 200, description: 'Completed', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ContractObligationResource')])),
            new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ContractNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/ContractConflict', response: 409),
            new OA\Response(ref: '#/components/responses/ContractUnprocessable', response: 422),
        ]
    )]
    public function complete(Request $request, string $obligationId): JsonResponse
    {
        return $this->close($request, $obligationId, 'completed');
    }

    /**
     * Waive an obligation.
     */
    #[OA\Post(
        path: '/api/v1/contract-register/obligations/{obligationId}/waive',
        operationId: 'waiveContractObligation',
        description: 'Closes an open obligation or milestone that no longer applies (agreed with the counterparty), with an optional note. It stays on record.',
        summary: 'Waive an obligation or milestone',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/ContractCloseObligationRequest')),
        tags: ['Contract Obligations'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ContractObligationIdPath')],
        responses: [
            new OA\Response(response: 200, description: 'Waived', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ContractObligationResource')])),
            new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ContractNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/ContractConflict', response: 409),
            new OA\Response(ref: '#/components/responses/ContractUnprocessable', response: 422),
        ]
    )]
    public function waive(Request $request, string $obligationId): JsonResponse
    {
        return $this->close($request, $obligationId, 'waived');
    }

    private function close(Request $request, string $obligationId, string $outcome): JsonResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:1000']])['note'] ?? null;
        $obligation = $this->obligations->close(ContractObligation::query()->findOrFail($obligationId), $outcome, $note, (string) $request->user()->id);

        return $this->successResponse(new ContractObligationResource($obligation), "Obligation {$outcome}");
    }
}

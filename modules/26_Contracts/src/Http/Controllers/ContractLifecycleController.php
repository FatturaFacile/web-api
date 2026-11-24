<?php

declare(strict_types=1);

namespace Modules\Contracts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Contracts\Enums\ContractKind;
use Modules\Contracts\Enums\ContractStatus;
use Modules\Contracts\Http\Requests\TransitionContractRequest;
use Modules\Contracts\Http\Resources\ContractResource;
use Modules\Contracts\Models\ContractStatusChange;
use Modules\Contracts\Services\ContractLifecycle;
use Modules\Contracts\Services\ContractRegister;
use OpenApi\Attributes as OA;

/**
 * The lifecycle of a contract: draft, review, active, renewal, expired and terminated, with its history.
 */
final class ContractLifecycleController extends Controller
{
    use ApiResponse;

    /**
     * Inject the collaborators.
     */
    public function __construct(private readonly ContractRegister $register, private readonly ContractLifecycle $lifecycle) {}

    /**
     * Change the status of a contract.
     */
    #[OA\Post(
        path: '/api/v1/contract-register/{kind}/{id}/transition',
        operationId: 'transitionContract',
        description: 'Moves a contract along its lifecycle: draft to review or active, review back to draft or on to active, active to renewal (still in force while the renewal is negotiated), expired or terminated; renewal back to active (renewing the contract does it too), expired or terminated; expired to renewal or active. Terminated is final and needs a reason, and only owners, administrators, accountants and the manager of that kind of contract can terminate. A contract locked by an approval workflow is not moved from here. Every change is kept in the history.',
        summary: 'Change contract status',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ContractTransitionRequest')),
        tags: ['Contract Lifecycle'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ContractKindPath'), new OA\Parameter(ref: '#/components/parameters/ContractIdPath')],
        responses: [
            new OA\Response(response: 200, description: 'The contract with its new status', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ContractResource')])),
            new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ContractNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/ContractConflict', response: 409),
            new OA\Response(ref: '#/components/responses/ContractUnprocessable', response: 422),
        ]
    )]
    public function transition(TransitionContractRequest $request, string $kind, string $id): JsonResponse
    {
        $contractKind = ContractKind::from($kind);
        $contract = $this->register->find($contractKind, $id);
        $target = ContractStatus::from($request->validated('status'));

        if ($target === ContractStatus::Terminated) {
            Gate::authorize('contracts.terminate', $contractKind);
        }

        $contract = $this->lifecycle->transition($contractKind, $contract, $target, $request->validated('reason'), (string) $request->user()->id);

        return $this->successResponse(new ContractResource($contract->refresh()), 'Contract status changed');
    }

    /**
     * Status history of a contract.
     */
    #[OA\Get(
        path: '/api/v1/contract-register/{kind}/{id}/history',
        operationId: 'getContractHistory',
        description: 'Every status change of the contract, newest first: from and to, why, and who (empty when the daily job expired it).',
        summary: 'Contract status history',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Contract Lifecycle'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ContractKindPath'), new OA\Parameter(ref: '#/components/parameters/ContractIdPath')],
        responses: [
            new OA\Response(response: 200, description: 'The history', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ContractStatusChangeResource'))])),
            new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ContractNotFound', response: 404),
        ]
    )]
    public function history(string $kind, string $id): JsonResponse
    {
        $contractKind = ContractKind::from($kind);
        $contract = $this->register->find($contractKind, $id);

        $changes = ContractStatusChange::query()
            ->where('contract_kind', $contractKind->value)
            ->where('contract_id', $contract->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(static fn (ContractStatusChange $change): array => [
                'id' => (string) $change->id,
                'from_status' => $change->from_status,
                'to_status' => $change->to_status,
                'reason' => $change->reason,
                'changed_by' => $change->changed_by,
                'created_at' => $change->created_at?->toIso8601String(),
            ])
            ->all();

        return $this->successResponse($changes, 'Contract history');
    }
}

<?php

declare(strict_types=1);

namespace Modules\Contracts\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Contracts\Enums\ContractKind;
use Modules\Contracts\Http\Controllers\Concerns\InteractsWithContractAuthoring;
use Modules\Contracts\Http\Requests\CloseRoundRequest;
use Modules\Contracts\Http\Requests\OpenRoundRequest;
use Modules\Contracts\Http\Resources\NegotiationRoundResource;
use Modules\Contracts\Models\ContractNegotiationRound;
use Modules\Contracts\Services\ContractRegister;
use Modules\Contracts\Services\RedliningService;
use OpenApi\Attributes as OA;

/**
 * Negotiation rounds of a contract: one open round at a time, closed with an outcome.
 */
final class NegotiationRoundController extends Controller
{
    use InteractsWithContractAuthoring;

    public function __construct(private readonly RedliningService $redlining, private readonly ContractRegister $register) {}

    #[OA\Get(path: '/api/v1/contract-authoring/contracts/{kind}/{id}/rounds', operationId: 'listContractNegotiationRounds', description: 'Rounds of the contract, newest first.', summary: 'List negotiation rounds', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Contract Authoring'], parameters: [new OA\Parameter(name: 'kind', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['customer', 'supplier'])), new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Rounds', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ContractNegotiationRoundResource'))])),
        new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ContractNotFound', response: 404),
    ])]
    public function index(string $kind, string $id): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ContractNegotiationRound::class);
        $this->register->find(ContractKind::from($kind), $id);

        return NegotiationRoundResource::collection(ContractNegotiationRound::query()->where('tenant_id', $this->tenantId())->where('contract_kind', $kind)->where('contract_id', $id)->orderByDesc('round_no')->get());
    }

    #[OA\Post(path: '/api/v1/contract-authoring/contracts/{kind}/{id}/rounds', operationId: 'openContractNegotiationRound', description: 'Open the next round; text versions stored while it is open belong to it. The open round must be closed first.', summary: 'Open a negotiation round', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ContractNegotiationRoundOpenRequest')), tags: ['Contract Authoring'], parameters: [new OA\Parameter(name: 'kind', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['customer', 'supplier'])), new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 201, description: 'Round opened', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ContractNegotiationRoundResource')])),
        new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ContractNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ContractUnprocessable', response: 422),
    ])]
    public function store(OpenRoundRequest $request, string $kind, string $id): JsonResponse
    {
        $this->authorize('create', ContractNegotiationRound::class);

        return (new NegotiationRoundResource($this->redlining->openNewRound(ContractKind::from($kind), $id, $request->validated('summary'), $this->userId())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Post(path: '/api/v1/contract-authoring/negotiation-rounds/{id}/close', operationId: 'closeContractNegotiationRound', description: 'Close the round with an outcome (agreed, countered, rejected, withdrawn).', summary: 'Close a negotiation round', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ContractNegotiationRoundCloseRequest')), tags: ['Contract Authoring'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Round closed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ContractNegotiationRoundResource')])),
        new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ContractNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ContractUnprocessable', response: 422),
    ])]
    public function close(CloseRoundRequest $request, string $id): NegotiationRoundResource
    {
        $round = ContractNegotiationRound::query()->findOrFail($id);
        $this->authorize('update', $round);
        $data = $request->validated();

        return new NegotiationRoundResource($this->redlining->closeRound($round, $data['outcome'], $data['summary'] ?? null));
    }
}

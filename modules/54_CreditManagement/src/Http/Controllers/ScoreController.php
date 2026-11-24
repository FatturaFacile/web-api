<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\CreditManagement\Http\Controllers\Concerns\InteractsWithCreditManagement;
use Modules\CreditManagement\Http\Requests\ScoreRequest;
use Modules\CreditManagement\Http\Resources\ScoreResource;
use Modules\CreditManagement\Models\CreditScore;
use Modules\CreditManagement\Services\CreditScoringService;
use OpenApi\Attributes as OA;

/**
 * Credit scores of the customers and the automatic credit limit proposal.
 */
final class ScoreController extends Controller
{
    use InteractsWithCreditManagement;

    public function __construct(private readonly CreditScoringService $scoring) {}

    #[OA\Get(path: '/api/v1/credit-management/scores', operationId: 'listCreditScores', description: 'Score history, latest first. Filter: customer_id.', summary: 'List scores', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Credit management'], responses: [
        new OA\Response(response: 200, description: 'List scores', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CreditScore'))])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CreditScore::class);
        $filters = $request->validate(['customer_id' => ['nullable', 'uuid']]);
        $page = CreditScore::query()
            ->where('tenant_id', $this->tenantId())
            ->when(isset($filters['customer_id']), static fn ($query) => $query->where('customer_id', $filters['customer_id']))
            ->orderByDesc('scored_on')
            ->orderByDesc('created_at')
            ->paginate($this->perPage());
        $this->withCustomerNames($page->getCollection());

        return ScoreResource::collection($page);
    }

    #[OA\Post(path: '/api/v1/credit-management/scores', operationId: 'storeCreditScore', description: 'Compute and store the score of a customer today from the active scorecard factors. The analyst gives the 0-100 value of every manual factor in manual_inputs, keyed by factor code.', summary: 'Score a customer', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CreditScoreRequest')), tags: ['Credit management'], responses: [
        new OA\Response(response: 201, description: 'Score a customer', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CreditScore')])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CreditUnprocessable', response: 422),
    ])]
    public function store(ScoreRequest $request): JsonResponse
    {
        $this->authorize('create', CreditScore::class);
        $data = $request->validated();
        $score = $this->scoring->score($this->tenantId(), $data['customer_id'], $data['manual_inputs'] ?? [], $this->userId(), $data['notes'] ?? null);
        $this->withCustomerNames([$score]);

        return (new ScoreResource($score))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/credit-management/scores/{id}', operationId: 'showCreditScore', description: 'A score with the breakdown per factor.', summary: 'Show a score', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Credit management'], parameters: [new OA\Parameter(ref: '#/components/parameters/CreditId')], responses: [
        new OA\Response(response: 200, description: 'Show a score', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CreditScore')])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CreditNotFound', response: 404),
    ])]
    public function show(string $id): ScoreResource
    {
        $score = CreditScore::query()->findOrFail($id);
        $this->authorize('view', $score);
        $this->withCustomerNames([$score]);

        return new ScoreResource($score);
    }

    #[OA\Get(path: '/api/v1/credit-management/customers/{customerId}/limit-proposal', operationId: 'showCreditLimitProposal', description: 'The credit limit the latest score of the customer supports: the band multiplier times the quarterly turnover (last twelve months divided by four), capped by the ceiling of the band, rounded down to the hundred. 422 when the customer has no score yet.', summary: 'Propose a credit limit', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Credit management'], parameters: [new OA\Parameter(ref: '#/components/parameters/CreditCustomerId')], responses: [
        new OA\Response(response: 200, description: 'Propose a credit limit', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CreditLimitProposal')])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CreditNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CreditUnprocessable', response: 422),
    ])]
    public function proposal(string $customerId): JsonResponse
    {
        $this->authorize('viewAny', CreditScore::class);

        return response()->json(['data' => $this->scoring->proposeLimit($this->tenantId(), $customerId)]);
    }
}

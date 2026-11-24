<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Http\Controllers\Concerns\ProcurementControllerHelpers;
use Modules\Procurement\Http\Requests\ConvertPurchaseSuggestionsRequest;
use Modules\Procurement\Http\Resources\PurchaseOrderResource;
use Modules\Procurement\Http\Resources\PurchaseSuggestionResource;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseSuggestion;
use Modules\Procurement\Services\PurchaseSuggestionService;
use OpenApi\Attributes as OA;

/**
 * Purchase suggestions from inventory reorder rules and MRP planned purchase orders, converted into purchase orders.
 */
#[OA\Tag(name: 'Procurement Suggestions', description: 'Purchase suggestions from reorder rules and MRP')]
final class PurchaseSuggestionController extends Controller
{
    use ProcurementControllerHelpers;

    public function __construct(private readonly PurchaseSuggestionService $suggestions) {}

    /**
     * Paginated suggestions.
     */
    #[OA\Get(
        path: '/api/v1/procurement/purchase-suggestions',
        operationId: 'procurementSuggestionsIndex',
        description: 'Purchase suggestions, optionally by status (open, converted, dismissed).',
        summary: 'List purchase suggestions',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Procurement Suggestions'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProcStatusFilter'), new OA\Parameter(ref: '#/components/parameters/ProcPage'), new OA\Parameter(ref: '#/components/parameters/ProcPerPage')],
        responses: [
            new OA\Response(response: 200, description: 'Suggestions', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProcurementSuggestionResource'))])),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = PurchaseSuggestion::query()->orderBy('required_by_date');

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        return PurchaseSuggestionResource::collection($query->gridPaginate($this->perPage($request)));
    }

    /**
     * Generates the missing suggestions.
     */
    #[OA\Post(
        path: '/api/v1/procurement/purchase-suggestions/generate',
        operationId: 'procurementSuggestionsGenerate',
        description: 'Evaluates the reorder rules (available plus on-order stock at or below the reorder point: order the economic quantity, or up to the maximum level) and the MRP planned purchase orders; an open suggestion is never duplicated. Each suggestion gets the cheapest valid supplier offer (the preferred supplier of the rule when it has one).',
        summary: 'Generate purchase suggestions',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Procurement Suggestions'],
        responses: [
            new OA\Response(response: 200, description: 'New suggestions', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProcurementSuggestionResource'))])),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ProcForbidden', response: 403),
        ]
    )]
    public function generate(): AnonymousResourceCollection
    {
        return PurchaseSuggestionResource::collection($this->suggestions->generate($this->tenantId()));
    }

    /**
     * Converts suggestions into purchase orders.
     *
     * @status 201
     *
     * @response array{data: list<PurchaseOrderResource>}
     */
    #[OA\Post(
        path: '/api/v1/procurement/purchase-suggestions/convert',
        operationId: 'procurementSuggestionsConvert',
        description: 'Creates one draft purchase order per supplier from open suggestions with a supplier offer.',
        summary: 'Convert purchase suggestions',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['suggestion_ids'], properties: [new OA\Property(property: 'suggestion_ids', type: 'array', minItems: 1, description: 'Open purchase suggestions to order; each needs a supplier offer', items: new OA\Items(type: 'string', format: 'uuid'))])),
        tags: ['Procurement Suggestions'],
        responses: [
            new OA\Response(response: 201, description: 'Purchase orders', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProcurementPurchaseOrder'))])),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ProcForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ProcUnprocessable', response: 422),
        ]
    )]
    public function convert(ConvertPurchaseSuggestionsRequest $request): JsonResponse
    {
        /** @var list<string> $ids */
        $ids = $request->validated('suggestion_ids') ?? [];

        if ($ids === []) {
            throw new ProcurementRuleViolation('Give the suggestions to order.', 'suggestion_ids');
        }

        $orders = $this->suggestions->convert($this->tenantId(), $ids, $this->actorId($request));

        return response()->json(['data' => $orders->map(static fn (PurchaseOrder $order): PurchaseOrderResource => new PurchaseOrderResource($order->load('lines')))->all()], 201);
    }

    /**
     * Dismisses a suggestion.
     */
    #[OA\Post(
        path: '/api/v1/procurement/purchase-suggestions/{id}/dismiss',
        operationId: 'procurementSuggestionsDismiss',
        description: 'Dismisses an open suggestion.',
        summary: 'Dismiss purchase suggestion',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Procurement Suggestions'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProcRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Suggestion dismissed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProcurementSuggestionResource')])),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ProcForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ProcNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/ProcUnprocessable', response: 422),
        ]
    )]
    public function dismiss(string $id): PurchaseSuggestionResource
    {
        return new PurchaseSuggestionResource($this->suggestions->dismiss(PurchaseSuggestion::query()->findOrFail($id)));
    }
}

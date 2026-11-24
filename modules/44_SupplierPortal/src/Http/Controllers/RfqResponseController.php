<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Procurement\Models\Rfq;
use Modules\SupplierPortal\Http\Controllers\Concerns\InteractsWithSupplierPortal;
use Modules\SupplierPortal\Models\SupplierRiskProfile;
use Modules\SupplierPortal\Services\RfqResponseService;
use OpenApi\Attributes as OA;

/**
 * Answers of the invited suppliers to the requests for quotation, compared side by side for the buyer. The award itself
 * stays in the procurement module.
 */
final class RfqResponseController extends Controller
{
    use InteractsWithSupplierPortal;

    public function __construct(private readonly RfqResponseService $responses) {}

    #[OA\Get(path: '/api/v1/supplier-portal/rfq-responses', operationId: 'listSupplierRfqResponses', description: 'Issued, awarded and cancelled requests for quotation with how many invited suppliers answered, declined or are still silent. Filter: status.', summary: 'List requests with their answers', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Supplier Portal'], parameters: [new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['issued', 'awarded', 'cancelled']))], responses: [
        new OA\Response(response: 200, description: 'Requests', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalForbidden', response: 403),
    ])]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SupplierRiskProfile::class);
        $request->validate(['status' => ['nullable', Rule::in(['issued', 'awarded', 'cancelled'])]]);

        return response()->json(['data' => $this->responses->summaries($request->filled('status') ? (string) $request->query('status') : null)->values()]);
    }

    #[OA\Get(path: '/api/v1/supplier-portal/rfq-responses/{rfqId}/comparison', operationId: 'compareSupplierRfqResponses', description: 'Side-by-side comparison of the quotes: per line the offers cheapest first, per quote the total, coverage and lead time, who was invited, answered or declined, the qualification and risk flag of each supplier, and the recommended quote (cheapest covering every line from a supplier not flagged high or critical).', summary: 'Compare the answers to a request', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Supplier Portal'], parameters: [new OA\Parameter(ref: '#/components/parameters/SupplierPortalRfqId')], responses: [
        new OA\Response(response: 200, description: 'Comparison', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SpRfqComparison')])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SupplierPortalNotFound', response: 404),
    ])]
    public function comparison(string $rfqId): JsonResponse
    {
        $this->authorize('viewAny', SupplierRiskProfile::class);
        $rfq = Rfq::query()->where('tenant_id', $this->tenantId())->findOrFail($rfqId);

        return response()->json(['data' => $this->responses->comparison($rfq)]);
    }
}

<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\CreditManagement\Contracts\ReceivablesLookupInterface;
use Modules\CreditManagement\Http\Controllers\Concerns\InteractsWithCreditManagement;
use Modules\CreditManagement\Models\CreditScore;
use Modules\CreditManagement\Services\ExposureService;
use OpenApi\Attributes as OA;

/**
 * Credit exposure per customer and the aging of the receivables.
 */
final class ExposureController extends Controller
{
    use InteractsWithCreditManagement;

    public function __construct(private readonly ExposureService $exposures) {}

    #[OA\Get(path: '/api/v1/credit-management/exposures', operationId: 'listCreditExposures', description: 'Exposure of every customer with open invoices, highest first: open invoices and committed orders against the credit limit (0 means no limit), overdue amount and insured limit. Filter: over_limit_only. Not paginated.', summary: 'List customer exposures', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Credit management'], responses: [
        new OA\Response(response: 200, description: 'List customer exposures', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CreditExposure'))])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
    ])]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CreditScore::class);
        $filters = $request->validate(['over_limit_only' => ['nullable', 'boolean']]);

        return response()->json(['data' => $this->exposures->listing($this->tenantId(), Carbon::today(), (bool) ($filters['over_limit_only'] ?? false))]);
    }

    #[OA\Get(path: '/api/v1/credit-management/customers/{customerId}/exposure', operationId: 'showCreditExposure', description: 'Exposure of one customer today.', summary: 'Show the exposure of a customer', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Credit management'], parameters: [new OA\Parameter(ref: '#/components/parameters/CreditCustomerId')], responses: [
        new OA\Response(response: 200, description: 'Show the exposure of a customer', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CreditExposure')])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CreditNotFound', response: 404),
    ])]
    public function show(string $customerId): JsonResponse
    {
        $this->authorize('viewAny', CreditScore::class);
        abort_if(app(ReceivablesLookupInterface::class)->customerName($this->tenantId(), $customerId) === null, 404);

        return response()->json(['data' => $this->exposures->forCustomer($this->tenantId(), $customerId, Carbon::today())]);
    }

    #[OA\Get(path: '/api/v1/credit-management/aging', operationId: 'showCreditAging', description: 'Open receivables by days overdue: current, 1-30, 31-60, 61-90 and over 90, in total and per customer. Filter: customer_id.', summary: 'Aging of the receivables', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Credit management'], responses: [
        new OA\Response(response: 200, description: 'Aging of the receivables', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CreditAging')])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
    ])]
    public function aging(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CreditScore::class);
        $filters = $request->validate(['customer_id' => ['nullable', 'uuid']]);

        return response()->json(['data' => $this->exposures->aging($this->tenantId(), Carbon::today(), $filters['customer_id'] ?? null)]);
    }
}

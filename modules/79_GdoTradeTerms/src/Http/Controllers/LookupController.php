<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\GdoTradeTerms\Http\Controllers\Concerns\InteractsWithGdo;
use Modules\GdoTradeTerms\Models\GdoContract;
use Modules\GdoTradeTerms\Services\LookupService;
use OpenApi\Attributes as OA;

/**
 * Reference data of the screens (the labels of the enumerations are in the frontend dictionary served by the API).
 */
final class LookupController extends Controller
{
    use ApiResponse;
    use InteractsWithGdo;

    #[OA\Get(path: '/api/v1/gdo-trade-terms/lookups', operationId: 'gdoTradeTermsLookups', summary: 'Enumerations, tax rates and reference lists of the module', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['GDO Trade Terms'], responses: [new OA\Response(response: 200, description: 'Lookups')])]
    public function lookups(LookupService $lookups): JsonResponse
    {
        Gate::authorize('viewAny', GdoContract::class);

        return $this->successResponse($lookups->reference($this->tenantId()));
    }

    #[OA\Get(path: '/api/v1/gdo-trade-terms/products', operationId: 'gdoTradeTermsSearchProducts', summary: 'Search products for the scope of a scale or a promotion', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['GDO Trade Terms'], responses: [new OA\Response(response: 200, description: 'Products')])]
    public function products(Request $request, LookupService $lookups): JsonResponse
    {
        Gate::authorize('viewAny', GdoContract::class);

        return $this->successResponse($lookups->products($this->tenantId(), (string) $request->query('q', '')));
    }
}

<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\StatutoryFinancials\Application\Queries\GetLookupsHandler;
use Modules\StatutoryFinancials\Application\Queries\GetLookupsQuery;
use Modules\StatutoryFinancials\Http\Controllers\Concerns\InteractsWithStatutoryFinancials;
use Modules\StatutoryFinancials\Http\Requests\ListDossiersRequest;
use Modules\StatutoryFinancials\Models\StatutoryDossier;
use OpenApi\Attributes as OA;

/**
 * Seeded reference data and wording for the client.
 */
final class LookupController extends Controller
{
    use ApiResponse;
    use InteractsWithStatutoryFinancials;

    #[OA\Get(path: '/api/v1/statutory-financials/lookups', operationId: 'statutoryFinancialsLookups', summary: 'Standards, rounding modes, fiscal years, seeded wording and ratio definitions', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Statutory Financials'], responses: [new OA\Response(response: 200, description: 'Lookups')])]
    public function lookups(ListDossiersRequest $request, GetLookupsHandler $handler): JsonResponse
    {
        Gate::authorize('viewAny', StatutoryDossier::class);

        return $this->successResponse($handler->handle(new GetLookupsQuery((string) $request->input('locale', app()->getLocale()))));
    }
}

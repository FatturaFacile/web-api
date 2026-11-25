<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\GdoTradeTerms\Application\Queries\GetDashboardHandler;
use Modules\GdoTradeTerms\Application\Queries\GetDashboardQuery;
use Modules\GdoTradeTerms\Application\Queries\GetNetNetMarginHandler;
use Modules\GdoTradeTerms\Application\Queries\GetNetNetMarginQuery;
use Modules\GdoTradeTerms\Http\Controllers\Concerns\InteractsWithGdo;
use Modules\GdoTradeTerms\Http\Requests\AsOfRequest;
use Modules\GdoTradeTerms\Http\Requests\MarginRequest;
use Modules\GdoTradeTerms\Models\GdoContract;
use OpenApi\Attributes as OA;

/**
 * Read models for the commercial controllers: the dashboard and the gross / net / net-net margin.
 */
final class ReportController extends Controller
{
    use ApiResponse;
    use InteractsWithGdo;

    #[OA\Get(path: '/api/v1/gdo-trade-terms/dashboard', operationId: 'gdoTradeTermsDashboard', summary: 'Contracts, accruals, settlements and year-end projections', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['GDO Trade Terms'], responses: [new OA\Response(response: 200, description: 'Dashboard')])]
    public function dashboard(AsOfRequest $request, GetDashboardHandler $handler): JsonResponse
    {
        Gate::authorize('viewAny', GdoContract::class);

        return $this->successResponse($handler->handle(new GetDashboardQuery($this->tenantId(), $request->input('as_of'))));
    }

    #[OA\Get(path: '/api/v1/gdo-trade-terms/net-net-margin', operationId: 'gdoTradeTermsNetNetMargin', summary: 'Gross, net and net-net margin of the sales to GDO customers', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['GDO Trade Terms'], responses: [new OA\Response(response: 200, description: 'Margin breakdown')])]
    public function margin(MarginRequest $request, GetNetNetMarginHandler $handler): JsonResponse
    {
        Gate::authorize('viewAny', GdoContract::class);

        return $this->successResponse($handler->handle(new GetNetNetMarginQuery(
            tenantId: $this->tenantId(),
            from: (string) $request->validated('from'),
            to: (string) $request->validated('to'),
            contractId: $request->validated('contract_id'),
            contactId: $request->validated('contact_id'),
            groupBy: (string) ($request->validated('group_by') ?? 'product'),
        )));
    }
}

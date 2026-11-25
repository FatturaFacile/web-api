<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\GdoTradeTerms\Application\Commands\ImportSellOutCommand;
use Modules\GdoTradeTerms\Application\Commands\ImportSellOutHandler;
use Modules\GdoTradeTerms\Application\Queries\GetSellOutReportsHandler;
use Modules\GdoTradeTerms\Application\Queries\GetSellOutReportsQuery;
use Modules\GdoTradeTerms\Http\Controllers\Concerns\InteractsWithGdo;
use Modules\GdoTradeTerms\Http\Requests\ImportSellOutRequest;
use Modules\GdoTradeTerms\Http\Requests\ListFiltersRequest;
use Modules\GdoTradeTerms\Http\Resources\SellOutReportResource;
use Modules\GdoTradeTerms\Models\GdoContract;
use Modules\GdoTradeTerms\Models\GdoSellOutReport;
use OpenApi\Attributes as OA;

/**
 * POS sell-out reported by the retailers.
 */
final class SellOutController extends Controller
{
    use ApiResponse;
    use InteractsWithGdo;

    #[OA\Get(path: '/api/v1/gdo-trade-terms/sell-out-reports', operationId: 'gdoTradeTermsListSellOut', summary: 'List the sell-out reported by the retailers', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['GDO Trade Terms'], responses: [new OA\Response(response: 200, description: 'Sell-out rows, paginated')])]
    public function index(ListFiltersRequest $request, GetSellOutReportsHandler $handler): JsonResponse
    {
        Gate::authorize('viewAny', GdoSellOutReport::class);

        return $this->paged($handler->handle(new GetSellOutReportsQuery($request->input('contract_id'), $request->input('delivery_point_id'), $request->input('from'), $request->input('to'), $this->perPage(50))), SellOutReportResource::class);
    }

    #[OA\Post(path: '/api/v1/gdo-trade-terms/contracts/{id}/sell-out-reports', operationId: 'gdoTradeTermsImportSellOut', summary: 'Import the sell-out of a retailer (a period sent again corrects the earlier row)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['GDO Trade Terms'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Rows created and corrected')])]
    public function import(ImportSellOutRequest $request, string $id, ImportSellOutHandler $handler): JsonResponse
    {
        Gate::authorize('update', GdoContract::query()->findOrFail($id));

        $result = $handler->handle(new ImportSellOutCommand($id, $request->validated('rows'), (string) ($request->validated('source') ?? 'manual'), $this->userId()));

        return $this->successResponse($result, $this->message('imported'));
    }
}

<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\IndustrialLaundry\Application\Commands\CalculateMonthlyLinenWashAndRentalInvoiceCommand;
use Modules\IndustrialLaundry\Application\Commands\CalculateMonthlyLinenWashAndRentalInvoiceHandler;
use Modules\IndustrialLaundry\Application\Commands\IssueBillingInvoiceCommand;
use Modules\IndustrialLaundry\Application\Commands\IssueBillingInvoiceHandler;
use Modules\IndustrialLaundry\Application\Queries\GetBillingRunsHandler;
use Modules\IndustrialLaundry\Application\Queries\GetBillingRunsQuery;
use Modules\IndustrialLaundry\Application\Queries\GetEntityDetailHandler;
use Modules\IndustrialLaundry\Application\Queries\GetEntityDetailQuery;
use Modules\IndustrialLaundry\Application\Queries\PreviewMonthlyBillingHandler;
use Modules\IndustrialLaundry\Application\Queries\PreviewMonthlyBillingQuery;
use Modules\IndustrialLaundry\Http\Controllers\Concerns\InteractsWithLaundry;
use Modules\IndustrialLaundry\Http\Requests\BillingPeriodRequest;
use Modules\IndustrialLaundry\Http\Requests\ListFiltersRequest;
use Modules\IndustrialLaundry\Http\Resources\BillingRunResource;
use Modules\IndustrialLaundry\Models\BillingRun;
use Modules\IndustrialLaundry\Models\ParContract;
use OpenApi\Attributes as OA;

/**
 * The monthly wash and rental billing: the preview, the consolidation with its draft invoice and the history of the runs.
 */
final class BillingController extends Controller
{
    use ApiResponse;
    use InteractsWithLaundry;

    #[OA\Post(path: '/api/v1/industrial-laundry/billing-previews', operationId: 'industrialLaundryBillingPreview', summary: 'Preview the wash, minimum, rental and unreturned garment charges of a period without storing anything', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Industrial Laundry'], responses: [new OA\Response(response: 200, description: 'Billing preview')])]
    public function preview(BillingPeriodRequest $request, PreviewMonthlyBillingHandler $handler): JsonResponse
    {
        Gate::authorize('viewAny', ParContract::class);

        $data = $request->validated();

        return $this->successResponse($handler->handle(new PreviewMonthlyBillingQuery((string) $data['contract_id'], (string) $data['period_start'], (string) $data['period_end'])), $this->message('listed'));
    }

    #[OA\Post(path: '/api/v1/industrial-laundry/billing-runs', operationId: 'industrialLaundryCalculateBilling', summary: 'Consolidate the monthly bill of a contract and draft its invoice (TD24 in Italy, QR-bill in Switzerland)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Industrial Laundry'], responses: [new OA\Response(response: 201, description: 'Billing run created')])]
    public function store(BillingPeriodRequest $request, CalculateMonthlyLinenWashAndRentalInvoiceHandler $handler): JsonResponse
    {
        Gate::authorize('bill', ParContract::class);

        $data = $request->validated();
        $run = $handler->handle(new CalculateMonthlyLinenWashAndRentalInvoiceCommand(
            tenantId: $this->tenantId(),
            userId: $this->userId(),
            contractId: (string) $data['contract_id'],
            periodStart: (string) $data['period_start'],
            periodEnd: (string) $data['period_end'],
            createInvoice: (bool) ($data['create_invoice'] ?? true),
        ));

        return $this->successResponse(new BillingRunResource($run->load(['customer', 'contract'])), $this->message('billed'), 201);
    }

    #[OA\Get(path: '/api/v1/industrial-laundry/billing-runs', operationId: 'industrialLaundryListBillingRuns', summary: 'List the billing runs', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Industrial Laundry'], responses: [new OA\Response(response: 200, description: 'Billing runs, paginated')])]
    public function index(ListFiltersRequest $request, GetBillingRunsHandler $handler): JsonResponse
    {
        Gate::authorize('viewAny', BillingRun::class);

        return $this->paged($handler->handle(new GetBillingRunsQuery($request->input('customer_id'), $request->input('status'), $this->perPage())), BillingRunResource::class);
    }

    #[OA\Get(path: '/api/v1/industrial-laundry/billing-runs/{id}', operationId: 'industrialLaundryShowBillingRun', summary: 'Read a billing run with its lines and compliance report', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Industrial Laundry'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Billing run')])]
    public function show(string $id, GetEntityDetailHandler $handler): JsonResponse
    {
        $run = $handler->handle(new GetEntityDetailQuery(GetEntityDetailQuery::BillingRun, $id));
        Gate::authorize('view', $run);

        return $this->successResponse(new BillingRunResource($run), $this->message('listed'));
    }

    #[OA\Post(path: '/api/v1/industrial-laundry/billing-runs/{id}/invoice', operationId: 'industrialLaundryInvoiceBillingRun', summary: 'Draft the sales invoice of a billing run stored without one', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Industrial Laundry'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Invoice drafted')])]
    public function invoice(string $id, IssueBillingInvoiceHandler $handler): JsonResponse
    {
        Gate::authorize('bill', ParContract::class);
        Gate::authorize('update', BillingRun::query()->findOrFail($id));

        return $this->successResponse(new BillingRunResource($handler->handle(new IssueBillingInvoiceCommand($id, $this->userId()))->load(['customer', 'contract'])), $this->message('invoiced'));
    }
}

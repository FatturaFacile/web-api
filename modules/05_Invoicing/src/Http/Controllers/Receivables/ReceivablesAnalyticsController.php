<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Controllers\Receivables;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Modules\Invoicing\Http\Requests\Receivables\PostProvisionRequest;
use Modules\Invoicing\Http\Requests\Receivables\ReceivablesQueryRequest;
use Modules\Invoicing\Http\Requests\Receivables\SaveProvisionRatesRequest;
use Modules\Invoicing\Services\Receivables\BadDebtProvisionPostingService;
use Modules\Invoicing\Services\Receivables\BadDebtProvisionService;
use Modules\Invoicing\Services\Receivables\DsoService;
use Modules\Invoicing\Services\Receivables\ReceivableAgingService;
use Modules\Invoicing\Services\Receivables\ReceivablesDashboardService;
use OpenApi\Attributes as OA;

final class ReceivablesAnalyticsController extends ReceivablesController
{
    #[OA\Get(path: '/api/v1/invoicing/receivables/dashboard', operationId: 'getInvoicingReceivablesDashboard', summary: 'AR and credit dashboard: exposure, overdue, DSO, provision, blocked customers, aging', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Invoicing Receivables'], responses: [new OA\Response(response: 200, description: 'Dashboard KPIs and aging'), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function dashboard(ReceivablesQueryRequest $request, ReceivablesDashboardService $dashboard): JsonResponse
    {
        return response()->json(['data' => $dashboard->summary($this->tenantId(), $this->today($request), $request->validated('currency'))]);
    }

    #[OA\Get(path: '/api/v1/invoicing/receivables/aging', operationId: 'getInvoicingReceivablesAging', summary: 'Aging of the open receivables, portfolio-wide or per customer', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Invoicing Receivables'], responses: [new OA\Response(response: 200, description: 'Aging buckets and customer rows'), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function aging(ReceivablesQueryRequest $request, ReceivableAgingService $aging): JsonResponse
    {
        $validated = $request->validated();

        return response()->json(['data' => $aging->aging($this->tenantId(), $this->today($request), $validated['customer_id'] ?? null, $validated['currency'] ?? null)]);
    }

    #[OA\Get(path: '/api/v1/invoicing/receivables/dso', operationId: 'getInvoicingReceivablesDso', summary: 'Days Sales Outstanding, global or per customer', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Invoicing Receivables'], responses: [new OA\Response(response: 200, description: 'DSO with its inputs'), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function dso(ReceivablesQueryRequest $request, DsoService $dso): JsonResponse
    {
        $validated = $request->validated();

        return response()->json(['data' => $dso->calculate($this->tenantId(), $this->today($request), $validated['customer_id'] ?? null, (int) ($validated['period_days'] ?? DsoService::DefaultPeriodDays), $validated['currency'] ?? null)]);
    }

    #[OA\Get(path: '/api/v1/invoicing/receivables/provision', operationId: 'getInvoicingReceivablesProvision', summary: 'Prudential bad debt provision by aging bucket', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Invoicing Receivables'], responses: [new OA\Response(response: 200, description: 'Provision lines and totals'), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function provision(ReceivablesQueryRequest $request, BadDebtProvisionService $provision): JsonResponse
    {
        $validated = $request->validated();

        return response()->json(['data' => $provision->calculate($this->tenantId(), $this->today($request), $validated['customer_id'] ?? null, $validated['currency'] ?? null)]);
    }

    #[OA\Put(path: '/api/v1/invoicing/receivables/provision/rates', operationId: 'saveInvoicingReceivablesProvisionRates', summary: 'Configure the provision rates per aging bucket', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Invoicing Receivables'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')), responses: [new OA\Response(response: 200, description: 'Configured rates'), new OA\Response(response: 403, description: 'Not a credit manager', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function saveRates(SaveProvisionRatesRequest $request, BadDebtProvisionService $provision): JsonResponse
    {
        $this->authorizeManage();

        return response()->json(['data' => $provision->saveRates($this->tenantId(), (array) ($request->validated('rates') ?? []))]);
    }

    #[OA\Post(path: '/api/v1/invoicing/receivables/provision/post', operationId: 'postInvoicingReceivablesProvision', summary: 'Book the prudential bad debt provision to the general ledger (only the difference to what is already booked)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Invoicing Receivables'], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(type: 'object')), responses: [new OA\Response(response: 201, description: 'Provision posted'), new OA\Response(response: 200, description: 'Nothing to post: the provision is already booked'), new OA\Response(response: 403, description: 'Not a credit manager', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 422, description: 'Posting accounts or fiscal period missing', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function postProvision(PostProvisionRequest $request, BadDebtProvisionPostingService $posting): JsonResponse
    {
        $this->authorizeManage();
        $validated = $request->validated();
        $asOf = isset($validated['as_of']) ? CarbonImmutable::parse((string) $validated['as_of']) : $this->today($request);
        $result = $posting->post($this->tenantId(), $asOf, $validated['currency'] ?? null, $this->userId($request));

        return response()->json(['data' => [
            'posted' => $result['posted'],
            'total_provision' => $result['total_provision'],
            'previous_provision' => $result['previous_provision'],
            'adjustment_amount' => $result['adjustment_amount'],
            'posting' => $result['posting'] === null ? null : $result['posting']->toArray(),
        ]], $result['posted'] ? 201 : 200);
    }

    #[OA\Get(path: '/api/v1/invoicing/receivables/provision/postings', operationId: 'listInvoicingReceivablesProvisionPostings', summary: 'History of the provision postings to the general ledger', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Invoicing Receivables'], responses: [new OA\Response(response: 200, description: 'Postings, newest first'), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function provisionPostings(BadDebtProvisionPostingService $posting): JsonResponse
    {
        return response()->json(['data' => array_map(static fn ($row): array => $row->toArray(), $posting->history($this->tenantId()))]);
    }
}

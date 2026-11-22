<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Modules\CRM\Http\Requests\CustomerTimelineRequest;
use Modules\CRM\Http\Resources\Customer360Resource;
use Modules\CRM\Models\Account;
use Modules\CRM\Services\Customer360Service;
use OpenApi\Attributes as OA;

/**
 * Customer 360: one account across CRM, invoicing, maintenance contracts, helpdesk and field service.
 */
final class Customer360Controller extends Controller
{
    public function __construct(
        private readonly Customer360Service $service,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Returns the 360 summary (health and KPIs) of a customer.
     */
    #[OA\Get(
        path: '/api/v1/crm/customers/{id}/360',
        operationId: 'showCrmCustomer360',
        description: 'Returns the 360 summary of a customer account: health index and churn risk, lifetime value, open pipeline, overdue receivables, open tickets, SLA breach rate, MTTR, maintenance contract coverage and field intervention counts. The customer is a CRM account of the active tenant.',
        summary: 'Customer 360 summary',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Customer 360'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the CRM account', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Customer 360 summary', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'account', type: 'object'), new OA\Property(property: 'health', type: 'object'), new OA\Property(property: 'kpis', type: 'object')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Account not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $account = $this->account($id);

        return response()->json(['data' => new Customer360Resource($this->service->summary($account))]);
    }

    /**
     * Returns the merged, newest-first timeline of a customer.
     */
    #[OA\Get(
        path: '/api/v1/crm/customers/{id}/timeline',
        operationId: 'listCrmCustomerTimeline',
        description: 'Returns the newest-first stream of the touchpoints of a customer: CRM activities, opportunities, converted leads, invoices, tickets, field interventions and maintenance contracts. Filter by type and date window.',
        summary: 'Customer 360 timeline',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Customer 360'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the CRM account', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'types[]', in: 'query', required: false, description: 'Touchpoint types to include', schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string', enum: ['activity', 'opportunity', 'invoice', 'ticket', 'work_order', 'contract', 'lead']))),
            new OA\Parameter(name: 'from', in: 'query', required: false, description: 'Earliest occurrence', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, description: 'Latest occurrence', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, description: 'Maximum number of events (1-200)', schema: new OA\Schema(type: 'integer', default: 50)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Customer timeline', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Account not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Invalid filters', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function timeline(string $id, CustomerTimelineRequest $request): JsonResponse
    {
        $account = $this->account($id);
        $validated = $request->validated();

        $events = $this->service->timeline(
            $account,
            $validated['types'] ?? null,
            isset($validated['from']) ? Carbon::parse($validated['from'])->startOfDay() : null,
            isset($validated['to']) ? Carbon::parse($validated['to'])->endOfDay() : null,
            (int) ($validated['limit'] ?? 50),
        );

        return response()->json([
            'data' => array_map(fn (array $event): array => [...$event, 'label' => __('customer_360.timeline.'.$event['type'])], $events),
        ]);
    }

    private function account(string $id): Account
    {
        return Account::query()->where('tenant_id', $this->tenantContext->getTenantId())->findOrFail($id);
    }
}

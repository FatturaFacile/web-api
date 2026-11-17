<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Modules\SubscriptionBilling\Models\IndexationAdjustment;
use Modules\SubscriptionBilling\Models\InflationIndex;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\SubscriptionBilling\Services\IndexationService;
use Modules\SubscriptionBilling\Support\Money;
use OpenApi\Attributes as OA;

/**
 * Inflation indexation (ISTAT FOI, BFS LIK) of the recurring fees of the active company: the index series, the
 * indexation clause of a contract, the recalculation sheet and the adjustments applied. Every query runs in the
 * tenant scope; writes need the write access of the subscriptions module.
 */
final class IndexationController extends Controller
{
    public function __construct(
        private readonly IndexationService $indexation,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Months of an index series, latest first.
     */
    #[OA\Get(path: '/api/v1/subscriptions/indexation/indices', operationId: 'listSubscriptionInflationIndices', summary: 'List inflation index values', description: 'Monthly values of the ISTAT FOI and BFS LIK series of the active company, latest first.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Subscriptions Indexation'], parameters: [
        new OA\Parameter(name: 'index_type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['istat_foi', 'lik'])),
    ], responses: [
        new OA\Response(response: 200, description: 'Index values', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingForbidden', response: 403),
    ])]
    public function indices(Request $request): JsonResponse
    {
        $data = $request->validate(['index_type' => ['nullable', Rule::in(InflationIndex::TYPES)]]);

        return response()->json(['data' => InflationIndex::query()
            ->when(isset($data['index_type']), fn ($query) => $query->where('index_type', $data['index_type']))
            ->orderByDesc('reference_month')
            ->limit(240)
            ->get()
            ->map(fn (InflationIndex $index): array => $this->indexRow($index))
            ->all()]);
    }

    /**
     * Records (or corrects) the value of a month of a series.
     */
    #[OA\Post(path: '/api/v1/subscriptions/indexation/indices', operationId: 'recordSubscriptionInflationIndex', summary: 'Record an inflation index value', description: 'Creates or updates the value of an index series for a month (YYYY-MM).', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Subscriptions Indexation'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['index_type', 'reference_month', 'index_value'], properties: [
        new OA\Property(property: 'index_type', type: 'string', enum: ['istat_foi', 'lik']),
        new OA\Property(property: 'reference_month', type: 'string', example: '2026-10'),
        new OA\Property(property: 'index_value', type: 'number', example: 120.4),
        new OA\Property(property: 'base_period', type: 'string', nullable: true),
        new OA\Property(property: 'source', type: 'string', nullable: true),
    ])), responses: [
        new OA\Response(response: 201, description: 'Recorded', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingUnprocessable', response: 422),
    ])]
    public function recordIndex(Request $request): JsonResponse
    {
        $data = $request->validate([
            'index_type' => ['required', Rule::in(InflationIndex::TYPES)],
            'reference_month' => ['required', 'date_format:Y-m'],
            'index_value' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'base_period' => ['nullable', 'string', 'max:30'],
            'source' => ['nullable', 'string', 'max:120'],
        ]);

        $index = $this->indexation->recordIndex($data['index_type'], $data['reference_month'].'-01', (string) $data['index_value'], $data['base_period'] ?? null, $data['source'] ?? null);

        return response()->json(['message' => 'Index recorded', 'data' => $this->indexRow($index)], 201);
    }

    /**
     * Adjustments applied, latest first.
     */
    #[OA\Get(path: '/api/v1/subscriptions/indexation/adjustments', operationId: 'listSubscriptionIndexationAdjustments', summary: 'List indexation adjustments', description: 'Price adjustments applied to the contracts, with their recalculation sheet, latest first.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Subscriptions Indexation'], parameters: [
        new OA\Parameter(name: 'subscription_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ], responses: [
        new OA\Response(response: 200, description: 'Adjustments', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingForbidden', response: 403),
    ])]
    public function adjustments(Request $request): JsonResponse
    {
        $data = $request->validate(['subscription_id' => ['nullable', 'uuid']]);

        return response()->json(['data' => IndexationAdjustment::query()
            ->when(isset($data['subscription_id']), fn ($query) => $query->where('subscription_id', $data['subscription_id']))
            ->orderByDesc('effective_date')
            ->limit(200)
            ->get()
            ->map(fn (IndexationAdjustment $adjustment): array => $this->adjustmentRow($adjustment))
            ->all()]);
    }

    /**
     * Applies every adjustment due (what the nightly job does).
     */
    #[OA\Post(path: '/api/v1/subscriptions/indexation/process', operationId: 'processSubscriptionIndexations', summary: 'Apply the due indexations', description: 'Applies the adjustment of every contract whose indexation date has come; contracts whose index is not published yet are reported as skipped.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Subscriptions Indexation'], responses: [
        new OA\Response(response: 200, description: 'Outcome', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'applied', type: 'integer'), new OA\Property(property: 'skipped', type: 'array', items: new OA\Items(type: 'object'))])])),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingUnprocessable', response: 422),
    ])]
    public function process(Request $request): JsonResponse
    {
        $data = $request->validate(['as_of' => ['nullable', 'date']]);

        return response()->json(['data' => $this->indexation->processDue(
            (string) $this->tenantContext->getTenantId(),
            isset($data['as_of']) ? CarbonImmutable::parse($data['as_of']) : null
        )]);
    }

    /**
     * The recalculation sheet the adjustment would have, without applying it.
     */
    #[OA\Get(path: '/api/v1/sub/contracts/{id}/indexation/preview', operationId: 'previewSubContractIndexation', summary: 'Preview the indexation of a contract', description: 'Recalculation sheet (index of the base and reference months, variation, ratio, cap, fee before and after) at the effective date, default the next indexation date.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Subscriptions Indexation'], parameters: [
        new OA\Parameter(ref: '#/components/parameters/SubscriptionBillingContractId'),
        new OA\Parameter(name: 'effective_date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
    ], responses: [
        new OA\Response(response: 200, description: 'Recalculation sheet', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingUnprocessable', response: 422),
    ])]
    public function preview(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['effective_date' => ['nullable', 'date']]);

        try {
            $sheet = $this->indexation->preview($this->find($id), isset($data['effective_date']) ? CarbonImmutable::parse($data['effective_date']) : null);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $sheet]);
    }

    /**
     * Applies the adjustment of the cycle and notifies the customer.
     */
    #[OA\Post(path: '/api/v1/sub/contracts/{id}/indexation/apply', operationId: 'applySubContractIndexation', summary: 'Apply the indexation of a contract', description: 'Adjusts the recurring fee with the inflation index (once per cycle), records the recalculation sheet and sends it to the customer.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Subscriptions Indexation'], parameters: [new OA\Parameter(ref: '#/components/parameters/SubscriptionBillingContractId')], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'effective_date', type: 'string', format: 'date', nullable: true)])), responses: [
        new OA\Response(response: 201, description: 'Applied', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingUnprocessable', response: 422),
    ])]
    public function apply(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['effective_date' => ['nullable', 'date']]);
        $actor = $request->user()?->getAuthIdentifier();

        try {
            $adjustment = $this->indexation->apply(
                $this->find($id),
                isset($data['effective_date']) ? CarbonImmutable::parse($data['effective_date']) : null,
                $actor !== null ? (string) $actor : null
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Indexation applied', 'data' => $this->adjustmentRow($adjustment)], 201);
    }

    /**
     * Sets, changes or removes the indexation clause of a contract.
     */
    #[OA\Put(path: '/api/v1/sub/contracts/{id}/indexation', operationId: 'configureSubContractIndexation', summary: 'Set the indexation clause of a contract', description: 'indexation_type istat_foi or lik, the share of the variation applied, the maximum increase, the base month of the index and the first indexation date (default one year after the start). A null type removes the clause.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Subscriptions Indexation'], parameters: [new OA\Parameter(ref: '#/components/parameters/SubscriptionBillingContractId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
        new OA\Property(property: 'indexation_type', type: 'string', enum: ['istat_foi', 'lik'], nullable: true),
        new OA\Property(property: 'indexation_ratio_pct', type: 'number', default: 100),
        new OA\Property(property: 'indexation_cap_pct', type: 'number', nullable: true),
        new OA\Property(property: 'indexation_base_month', type: 'string', example: '2025-10', nullable: true),
        new OA\Property(property: 'next_indexation_date', type: 'string', format: 'date', nullable: true),
    ])), responses: [
        new OA\Response(ref: '#/components/responses/SubscriptionBillingContract', response: 200),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingUnprocessable', response: 422),
    ])]
    public function configure(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'indexation_type' => ['present', 'nullable', Rule::in(InflationIndex::TYPES)],
            'indexation_ratio_pct' => ['nullable', 'numeric', 'between:0,100'],
            'indexation_cap_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'indexation_base_month' => ['nullable', 'date_format:Y-m'],
            'next_indexation_date' => ['nullable', 'date'],
        ]);

        $subscription = $this->indexation->configure(
            $this->find($id),
            $data['indexation_type'],
            isset($data['indexation_ratio_pct']) ? (string) $data['indexation_ratio_pct'] : '100',
            isset($data['indexation_cap_pct']) ? (string) $data['indexation_cap_pct'] : null,
            isset($data['indexation_base_month']) ? CarbonImmutable::parse($data['indexation_base_month'].'-01') : null,
            isset($data['next_indexation_date']) ? CarbonImmutable::parse($data['next_indexation_date']) : null,
        );

        return response()->json(['message' => 'Indexation clause saved', 'data' => [
            'id' => (string) $subscription->id,
            'indexation_type' => $subscription->indexation_type,
            'indexation_ratio_pct' => Money::round2((string) $subscription->getRawOriginal('indexation_ratio_pct')),
            'indexation_cap_pct' => $subscription->getRawOriginal('indexation_cap_pct') !== null ? Money::round((string) $subscription->getRawOriginal('indexation_cap_pct'), 3) : null,
            'indexation_base_month' => $subscription->indexation_base_month?->toDateString(),
            'next_indexation_date' => $subscription->next_indexation_date?->toDateString(),
        ]]);
    }

    private function find(string $id): Subscription
    {
        return Subscription::query()->with(['plan.pricingTiers', 'items'])->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function indexRow(InflationIndex $index): array
    {
        return [
            'id' => (string) $index->id,
            'index_type' => $index->index_type,
            'reference_month' => $index->reference_month?->format('Y-m'),
            'index_value' => Money::round((string) $index->getRawOriginal('index_value'), 4),
            'base_period' => $index->base_period,
            'source' => $index->source,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function adjustmentRow(IndexationAdjustment $adjustment): array
    {
        return [
            'id' => (string) $adjustment->id,
            'subscription_id' => (string) $adjustment->subscription_id,
            'index_type' => $adjustment->index_type,
            'base_month' => $adjustment->base_month?->format('Y-m'),
            'reference_month' => $adjustment->reference_month?->format('Y-m'),
            'base_index' => Money::round((string) $adjustment->getRawOriginal('base_index'), 4),
            'new_index' => Money::round((string) $adjustment->getRawOriginal('new_index'), 4),
            'variation_pct' => Money::round((string) $adjustment->getRawOriginal('variation_pct'), 4),
            'applied_pct' => Money::round((string) $adjustment->getRawOriginal('applied_pct'), 4),
            'old_recurring_amount' => Money::round2((string) $adjustment->getRawOriginal('old_recurring_amount')),
            'new_recurring_amount' => Money::round2((string) $adjustment->getRawOriginal('new_recurring_amount')),
            'old_mrr' => Money::round2((string) $adjustment->getRawOriginal('old_mrr')),
            'new_mrr' => Money::round2((string) $adjustment->getRawOriginal('new_mrr')),
            'effective_date' => $adjustment->effective_date?->toDateString(),
            'notified_at' => $adjustment->notified_at?->toIso8601String(),
            'calculation_sheet' => $adjustment->calculation_sheet,
        ];
    }
}

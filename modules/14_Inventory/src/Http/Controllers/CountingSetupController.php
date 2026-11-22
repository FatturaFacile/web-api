<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Inventory\Http\Requests\AbcOverrideRequest;
use Modules\Inventory\Http\Requests\AbcRunRequest;
use Modules\Inventory\Http\Requests\GenerateAbcPlansRequest;
use Modules\Inventory\Http\Requests\UpdateCountPolicyRequest;
use Modules\Inventory\Http\Resources\InventoryAbcRunResource;
use Modules\Inventory\Http\Resources\InventoryCountPolicyResource;
use Modules\Inventory\Models\CycleCountPlan;
use Modules\Inventory\Models\InventoryAbcOverride;
use Modules\Inventory\Models\InventoryAbcRun;
use Modules\Inventory\Models\InventoryCountPolicy;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Services\AbcClassificationService;
use Modules\Inventory\Services\CycleCountPlanService;
use OpenApi\Attributes as OA;

/**
 * Set-up of physical inventory and cycle counting: the counting policy of the tenant, the stored ABC runs (historical
 * snapshots) with their manual overrides, and the generation of the A/B/C cycle count plans from the policy cadences.
 * Every write is a manager decision (owner/admin).
 */
#[OA\Tag(name: 'Inventory Counting Setup', description: 'Counting policy, ABC run history, manual ABC overrides and generated cycle count plans')]
final class CountingSetupController extends Controller
{
    #[OA\Get(
        path: '/api/v1/inventory/count-policy',
        operationId: 'invShowCountPolicy',
        summary: 'Show the counting policy',
        description: 'ABC shares and cadences, recount thresholds, approval levels and ledger accounts of the tenant; the defaults when none was saved.',
        tags: ['Inventory Counting Setup'],
        responses: [
            new OA\Response(response: 200, description: 'The policy'),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
        ]
    )]
    public function showPolicy(): JsonResponse
    {
        return response()->json(new InventoryCountPolicyResource(InventoryCountPolicy::forTenant($this->tenantId())));
    }

    #[OA\Put(
        path: '/api/v1/inventory/count-policy',
        operationId: 'invUpdateCountPolicy',
        summary: 'Update the counting policy',
        description: 'Manager decision (owner/admin). Partial update; creates the tenant policy on first save.',
        tags: ['Inventory Counting Setup'],
        responses: [
            new OA\Response(response: 200, description: 'The saved policy'),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
        ]
    )]
    public function updatePolicy(UpdateCountPolicyRequest $request): JsonResponse
    {
        $this->authorize('inventory.manage');

        $policy = InventoryCountPolicy::forTenant($this->tenantId());
        $data = $request->validated();

        if ($policy->exists) {
            $policy->update($data + ['updated_by' => (string) auth()->id()]);
        } else {
            $policy = InventoryCountPolicy::create($data + ['tenant_id' => $this->tenantId(), 'created_by' => (string) auth()->id()] + InventoryCountPolicy::DEFAULTS);
        }

        return response()->json(new InventoryCountPolicyResource($policy->refresh()));
    }

    #[OA\Get(
        path: '/api/v1/inventory/abc-runs',
        operationId: 'invListAbcRuns',
        summary: 'List stored ABC runs',
        description: 'The history of the ABC classification runs, newest first.',
        tags: ['Inventory Counting Setup'],
        responses: [
            new OA\Response(response: 200, description: 'The runs'),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
        ]
    )]
    public function indexRuns(Request $request): JsonResponse
    {
        $runs = InventoryAbcRun::query()
            ->when($request->filled('warehouse_id'), static fn ($query) => $query->where('warehouse_id', (string) $request->query('warehouse_id')))
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return response()->json(['data' => InventoryAbcRunResource::collection($runs)->resolve($request)]);
    }

    #[OA\Post(
        path: '/api/v1/inventory/abc-runs',
        operationId: 'invStoreAbcRun',
        summary: 'Run and store an ABC classification',
        description: 'Manager decision (owner/admin). Classifies with the policy defaults for what is not given, stores the run with its ranked items (historical snapshot) and, unless apply is false, sets the class of the items (manually overridden items keep theirs). Items created inside the window without consumption get the policy new-item class.',
        tags: ['Inventory Counting Setup'],
        responses: [
            new OA\Response(response: 201, description: 'The stored run with its items'),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
        ]
    )]
    public function storeRun(AbcRunRequest $request, AbcClassificationService $abc): JsonResponse
    {
        $this->authorize('inventory.manage');

        $run = $abc->run(
            $request->filled('period_days') ? (int) $request->input('period_days') : null,
            $request->filled('a_share') ? (string) $request->input('a_share') : null,
            $request->filled('b_share') ? (string) $request->input('b_share') : null,
            $request->filled('warehouse_id') ? (string) $request->input('warehouse_id') : null,
            (string) auth()->id(),
            $request->boolean('apply', true)
        );

        return response()->json(new InventoryAbcRunResource($run->load('items.item')), Response::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/api/v1/inventory/abc-runs/{id}',
        operationId: 'invShowAbcRun',
        summary: 'Show a stored ABC run',
        description: 'The run with its ranked items: rank, consumption value, cumulative share, class, previous class, override and new-item flags.',
        tags: ['Inventory Counting Setup'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/WmsRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'The run'),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
        ]
    )]
    public function showRun(string $id): JsonResponse
    {
        return response()->json(new InventoryAbcRunResource(InventoryAbcRun::query()->with('items.item')->findOrFail($id)));
    }

    #[OA\Get(
        path: '/api/v1/inventory/abc-overrides',
        operationId: 'invListAbcOverrides',
        summary: 'List the manual ABC overrides',
        description: 'The audit trail of manual class changes: item, previous and new class, reason, user and time.',
        tags: ['Inventory Counting Setup'],
        responses: [
            new OA\Response(response: 200, description: 'The overrides'),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
        ]
    )]
    public function indexOverrides(Request $request): JsonResponse
    {
        $rows = InventoryAbcOverride::query()
            ->when($request->filled('stock_item_id'), static fn ($query) => $query->where('stock_item_id', (string) $request->query('stock_item_id')))
            ->orderByDesc('set_at')
            ->limit(200)
            ->get(['id', 'stock_item_id', 'previous_class', 'new_class', 'reason', 'set_by', 'set_at']);

        return response()->json(['data' => $rows]);
    }

    #[OA\Post(
        path: '/api/v1/inventory/abc-overrides',
        operationId: 'invStoreAbcOverride',
        summary: 'Set or clear the ABC class of an item by hand',
        description: 'Manager decision (owner/admin). The class is kept through later runs until cleared (abc_class null). A reason is mandatory and the change is audited.',
        tags: ['Inventory Counting Setup'],
        responses: [
            new OA\Response(response: 201, description: 'The audit row'),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
        ]
    )]
    public function storeOverride(AbcOverrideRequest $request, AbcClassificationService $abc): JsonResponse
    {
        $this->authorize('inventory.manage');

        $audit = $abc->override(
            StockItem::query()->findOrFail((string) $request->input('stock_item_id')),
            $request->input('abc_class'),
            (string) $request->input('reason'),
            (string) auth()->id()
        );

        return response()->json($audit->only(['id', 'stock_item_id', 'previous_class', 'new_class', 'reason', 'set_by']) + ['set_at' => $audit->set_at->toIso8601String()], Response::HTTP_CREATED);
    }

    #[OA\Post(
        path: '/api/v1/inventory/cycle-count-plans/generate',
        operationId: 'invGenerateAbcCycleCountPlans',
        summary: 'Generate the A/B/C cycle count plans of a warehouse',
        description: 'Manager decision (owner/admin). One plan per class with the cadence of the counting policy (A monthly, B quarterly, C semi-annual by default); existing plans are updated, new ones are due at once.',
        tags: ['Inventory Counting Setup'],
        responses: [
            new OA\Response(response: 201, description: 'The plans of the classes A, B and C'),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
        ]
    )]
    public function generatePlans(GenerateAbcPlansRequest $request, CycleCountPlanService $plans): JsonResponse
    {
        $this->authorize('inventory.manage');

        $generated = $plans->generateAbcPlans(
            (string) $request->input('warehouse_id'),
            (string) auth()->id(),
            $request->boolean('freezes_stock'),
            $request->filled('warehouse_zone_id') ? (string) $request->input('warehouse_zone_id') : null
        );

        return response()->json(['data' => array_map(static fn (CycleCountPlan $plan): array => [
            'id' => (string) $plan->id,
            'warehouse_id' => (string) $plan->warehouse_id,
            'warehouse_zone_id' => $plan->warehouse_zone_id !== null ? (string) $plan->warehouse_zone_id : null,
            'name' => (string) $plan->name,
            'abc_class' => $plan->abc_class,
            'interval_days' => (int) $plan->interval_days,
            'cadence' => InventoryCountPolicy::cadenceLabel((int) $plan->interval_days),
            'freezes_stock' => (bool) $plan->freezes_stock,
            'is_active' => (bool) $plan->is_active,
            'next_run_on' => $plan->next_run_on?->toDateString(),
        ], $generated)], Response::HTTP_CREATED);
    }

    private function tenantId(): ?string
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        return $tenantId === null ? null : (string) $tenantId;
    }
}

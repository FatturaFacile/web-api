<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AssetManagement\Contracts\MaintenanceManagementServiceInterface;
use Modules\AssetManagement\DTOs\CompleteMaintenanceWorkOrderDTO;
use Modules\AssetManagement\DTOs\CreateMaintenanceWorkOrderDTO;
use Modules\AssetManagement\Http\Requests\CompleteMaintenanceWorkOrderRequest;
use Modules\AssetManagement\Http\Requests\StoreMaintenanceWorkOrderRequest;
use Modules\AssetManagement\Http\Resources\MaintenanceWorkOrderResource;
use Modules\AssetManagement\Models\MaintenancePlan;
use Modules\AssetManagement\Models\MaintenanceWorkOrder;

final class MaintenanceController extends Controller
{
    public function __construct(
        private readonly MaintenanceManagementServiceInterface $maintenanceService,
        private readonly TenantContext $tenantContext
    ) {}

    public function indexPlans(): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $plans = MaintenancePlan::where('tenant_id', $tenant->id)->paginate(25);

        return response()->json(['data' => $plans->items()]);
    }

    public function storePlan(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $actorId = (string) ($request->user()?->id ?? '');

        $data = $request->all();
        $data['tenant_id'] = (string) $tenant->id;

        $plan = $this->maintenanceService->createMaintenancePlan($data, $actorId);

        return response()->json(['data' => $plan], 201);
    }

    public function indexWorkOrders(): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $workOrders = MaintenanceWorkOrder::where('tenant_id', $tenant->id)
            ->orderByDesc('scheduled_date')
            ->paginate(25);

        return response()->json([
            'data' => MaintenanceWorkOrderResource::collection($workOrders),
            'meta' => [
                'current_page' => $workOrders->currentPage(),
                'total' => $workOrders->total(),
            ],
        ]);
    }

    public function storeWorkOrder(StoreMaintenanceWorkOrderRequest $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $actorId = (string) ($request->user()?->id ?? '');

        $wo = $this->maintenanceService->createWorkOrder(new CreateMaintenanceWorkOrderDTO(
            tenantId: (string) $tenant->id,
            assetId: (string) $request->input('asset_id'),
            maintenanceType: (string) $request->input('maintenance_type', 'preventive'),
            priority: (string) $request->input('priority', 'medium'),
            scheduledDate: $request->input('scheduled_date'),
            planId: $request->input('plan_id'),
            assignedTechnicianId: $request->input('assigned_technician_id'),
            problemDescription: $request->input('problem_description'),
            estimatedCost: (float) $request->input('estimated_cost', 0.0)
        ), $actorId);

        return response()->json(['data' => new MaintenanceWorkOrderResource($wo)], 201);
    }

    public function showWorkOrder(string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $wo = MaintenanceWorkOrder::where('tenant_id', $tenant->id)->findOrFail($id);

        return response()->json(['data' => new MaintenanceWorkOrderResource($wo)]);
    }

    public function completeWorkOrder(CompleteMaintenanceWorkOrderRequest $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $wo = MaintenanceWorkOrder::where('tenant_id', $tenant->id)->findOrFail($id);
        $actorId = (string) ($request->user()?->id ?? '');

        $completed = $this->maintenanceService->completeWorkOrder(new CompleteMaintenanceWorkOrderDTO(
            workOrderId: (string) $wo->id,
            downtimeHours: (float) $request->input('downtime_hours', 0.0),
            laborCost: (float) $request->input('labor_cost', 0.0),
            partsCost: (float) $request->input('parts_cost', 0.0),
            externalServiceCost: (float) $request->input('external_service_cost', 0.0),
            capitalizeAsUpgrade: (bool) $request->input('capitalize_as_upgrade', false),
            conditionAfterMaintenance: (string) $request->input('condition_after_maintenance', 'good'),
            resolutionNotes: $request->input('resolution_notes'),
            partsConsumed: $request->input('parts_consumed', [])
        ), $actorId);

        return response()->json(['data' => new MaintenanceWorkOrderResource($completed)]);
    }
}

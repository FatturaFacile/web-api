<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\ServiceManagement\Contracts\IncidentManagementServiceInterface;
use Modules\ServiceManagement\Contracts\ProblemAndChangeServiceInterface;
use Modules\ServiceManagement\DTOs\CreateChangeRequestDTO;
use Modules\ServiceManagement\DTOs\CreateIncidentDTO;
use Modules\ServiceManagement\Http\Requests\StoreChangeRequestRequest;
use Modules\ServiceManagement\Http\Requests\StoreIncidentRequest;
use Modules\ServiceManagement\Http\Resources\ChangeRequestResource;
use Modules\ServiceManagement\Http\Resources\IncidentResource;
use Modules\ServiceManagement\Models\ChangeRequest;
use Modules\ServiceManagement\Models\Incident;
use Modules\ServiceManagement\Models\Problem;

final class ITSMController extends Controller
{
    public function __construct(
        private readonly IncidentManagementServiceInterface $incidentService,
        private readonly ProblemAndChangeServiceInterface $itsmService,
        private readonly TenantContext $tenantContext
    ) {}

    public function incidents(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $incidents = Incident::where('tenant_id', $tenant->id)->orderByDesc('created_at')->paginate(25);

        return response()->json([
            'data' => IncidentResource::collection($incidents),
            'meta' => [
                'current_page' => $incidents->currentPage(),
                'total' => $incidents->total(),
            ],
        ]);
    }

    public function storeIncident(StoreIncidentRequest $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $validated = $request->validated();

        $dto = new CreateIncidentDTO(
            tenantId: $tenant->id,
            companyId: $tenant->id,
            title: $validated['title'],
            description: $validated['description'] ?? null,
            severity: $validated['severity'] ?? 'medium',
            impact: $validated['impact'] ?? 'moderate',
            urgency: $validated['urgency'] ?? 'medium',
            serviceCategoryId: $validated['service_category_id'] ?? null,
            affectedAssetId: $validated['affected_asset_id'] ?? null,
            reportedById: $request->user()?->id
        );

        $incident = $this->incidentService->createIncident($dto, $request->user()?->id);

        return response()->json([
            'message' => 'Incident recorded successfully',
            'data' => new IncidentResource($incident),
        ], 201);
    }

    public function resolveIncident(Incident $incident, Request $request): JsonResponse
    {
        $resolved = $this->incidentService->resolveIncident(
            $incident->id,
            $request->input('resolution_summary'),
            $request->user()?->id
        );

        return response()->json([
            'message' => 'Incident resolved successfully',
            'data' => new IncidentResource($resolved),
        ]);
    }

    public function problems(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $problems = Problem::where('tenant_id', $tenant->id)->orderByDesc('created_at')->paginate(25);

        return response()->json([
            'data' => $problems,
        ]);
    }

    public function changes(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $changes = ChangeRequest::where('tenant_id', $tenant->id)->orderByDesc('created_at')->paginate(25);

        return response()->json([
            'data' => ChangeRequestResource::collection($changes),
            'meta' => [
                'current_page' => $changes->currentPage(),
                'total' => $changes->total(),
            ],
        ]);
    }

    public function storeChange(StoreChangeRequestRequest $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $validated = $request->validated();

        $dto = new CreateChangeRequestDTO(
            tenantId: $tenant->id,
            companyId: $tenant->id,
            title: $validated['title'],
            description: $validated['description'] ?? null,
            changeType: $validated['change_type'] ?? 'standard',
            riskLevel: $validated['risk_level'] ?? 'medium',
            priority: $validated['priority'] ?? 'medium',
            serviceCategoryId: $validated['service_category_id'] ?? null,
            rolloutPlan: $validated['rollout_plan'] ?? null,
            rollbackPlan: $validated['rollback_plan'] ?? null,
            testPlan: $validated['test_plan'] ?? null,
        );

        $change = $this->itsmService->createChangeRequest($dto, $request->user()?->id);

        return response()->json([
            'message' => 'Change request submitted successfully',
            'data' => new ChangeRequestResource($change),
        ], 201);
    }

    public function approveChange(ChangeRequest $change, Request $request): JsonResponse
    {
        $approved = $this->itsmService->approveChangeRequest(
            $change->id,
            $request->user()?->id ?? 'system',
            $request->user()?->id
        );

        return response()->json([
            'message' => 'Change request approved successfully',
            'data' => new ChangeRequestResource($approved),
        ]);
    }
}

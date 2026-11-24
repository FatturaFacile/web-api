<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Modules\ResourcePlanning\Application\Handlers\FindBestMatchingResourcesBySkillHandler;
use Modules\ResourcePlanning\Application\Handlers\GetResourceCapacityHeatmapHandler;
use Modules\ResourcePlanning\Application\Queries\FindBestMatchingResourcesBySkillQuery;
use Modules\ResourcePlanning\Application\Queries\GetResourceCapacityHeatmapQuery;
use Modules\ResourcePlanning\Application\Services\CapacitySnapshotService;
use Modules\ResourcePlanning\Domain\Contracts\ResourceDirectoryPort;
use Modules\ResourcePlanning\Domain\ValueObjects\ResourceProfile;
use Modules\ResourcePlanning\Presentation\Http\Controllers\Concerns\MapsResourcePlanningErrors;
use Modules\ResourcePlanning\Presentation\Http\Requests\CandidateSearchRequest;
use Modules\ResourcePlanning\Presentation\Http\Requests\PlanningWindowRequest;
use Modules\ResourcePlanning\Presentation\Http\Requests\RecalculateSnapshotsRequest;
use Modules\ResourcePlanning\Presentation\Http\Resources\CapacitySnapshotResource;
use OpenApi\Attributes as OA;

/**
 * Capacity of the workforce (Mod 079): the saturation heatmap, the resource lookup, the candidate suggestions for a role and
 * the weekly capacity snapshots.
 */
final class CapacityController extends Controller
{
    use ApiResponse;
    use MapsResourcePlanningErrors;

    public function __construct(private readonly TenantContext $tenantContext) {}

    #[OA\Get(path: '/api/v1/resource-planning/capacity/heatmap', operationId: 'getResourceCapacityHeatmap', summary: 'Capacity heatmap', description: 'One row per employee and one cell per week (or day): net hours after holidays (national, Swiss cantonal, Italian regional and patron saint) and approved leave, committed and tentative hours, load percent and colour band (green below 80, yellow up to 100, red above = overload).', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Resource Planning'], responses: [new OA\Response(response: 200, description: 'The heatmap')])]
    public function heatmap(PlanningWindowRequest $request, GetResourceCapacityHeatmapHandler $handler): JsonResponse
    {
        $from = $this->date($request->validated('from'), CarbonImmutable::today());
        $employees = $request->validated('employee_id');

        return $this->successResponse($handler->handle(new GetResourceCapacityHeatmapQuery(
            (string) $this->tenantContext->getTenantId(),
            $from,
            $this->date($request->validated('to'), $from->addWeeks(11)),
            $request->validated('granularity', 'week') !== 'day',
            $request->validated('department_id'),
            is_array($employees) && $employees !== [] ? array_values(array_map('strval', $employees)) : null,
        )));
    }

    #[OA\Get(path: '/api/v1/resource-planning/resources', operationId: 'listPlanningResources', summary: 'Employees that can be planned', description: 'The employees with an active contract, with their contractual hours, FTE, cost dimensions and working location.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Resource Planning'], responses: [new OA\Response(response: 200, description: 'The employees')])]
    public function resources(PlanningWindowRequest $request, ResourceDirectoryPort $directory): JsonResponse
    {
        return $this->successResponse(array_map(static fn (ResourceProfile $profile): array => $profile->toLookup(), $directory->resources(null, $request->validated('department_id'), CarbonImmutable::today())));
    }

    #[OA\Post(path: '/api/v1/resource-planning/candidates/search', operationId: 'findBestMatchingResources', summary: 'Rank the employees that fit a role', description: 'Matches required skills and minimum proficiency (1..5), the validity of the skills and of the safety certificates (Mod 099) and the net residual availability over the window.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Resource Planning'], responses: [new OA\Response(response: 200, description: 'The ranked candidates')])]
    public function candidates(CandidateSearchRequest $request, FindBestMatchingResourcesBySkillHandler $handler): JsonResponse
    {
        $data = $request->validated();

        return $this->successResponse($handler->handle(new FindBestMatchingResourcesBySkillQuery(
            (string) $this->tenantContext->getTenantId(),
            CarbonImmutable::parse($data['start_date']),
            CarbonImmutable::parse($data['end_date']),
            (float) $data['allocated_hours_per_day'],
            array_map(static fn (array $row): array => ['skill_id' => (string) $row['skill_id'], 'min_level' => (int) $row['min_level']], $data['requirements']),
            $data['department_id'] ?? null,
            (bool) ($data['require_valid_certificates'] ?? true),
            (bool) ($data['include_ineligible'] ?? false),
            (int) ($data['limit'] ?? 10),
        )));
    }

    #[OA\Get(path: '/api/v1/resource-planning/capacity/snapshots', operationId: 'listResourceCapacitySnapshots', summary: 'Weekly capacity snapshots', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Resource Planning'], responses: [new OA\Response(response: 200, description: 'The snapshots')])]
    public function snapshots(PlanningWindowRequest $request, CapacitySnapshotService $snapshots): JsonResponse
    {
        $from = $this->date($request->validated('from'), CarbonImmutable::today()->startOfWeek());
        $employees = $request->validated('employee_id');

        return $this->successResponse(CapacitySnapshotResource::collection($snapshots->snapshots(
            $from,
            $this->date($request->validated('to'), $from->addWeeks(11)),
            is_array($employees) && $employees !== [] ? (string) $employees[0] : null,
        )));
    }

    #[OA\Post(path: '/api/v1/resource-planning/capacity/snapshots/recalculate', operationId: 'recalculateResourceCapacitySnapshots', summary: 'Recompute the weekly capacity snapshots', description: 'Brings the snapshots of the window in step with contracts, calendars, leave and bookings (up to one year).', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Resource Planning'], responses: [new OA\Response(response: 200, description: 'How many weeks and snapshots were recomputed')])]
    public function recalculate(RecalculateSnapshotsRequest $request, CapacitySnapshotService $snapshots): JsonResponse
    {
        $data = $request->validated();

        return $this->successResponse($snapshots->sync(
            isset($data['employee_id']) ? [(string) $data['employee_id']] : null,
            CarbonImmutable::parse($data['from']),
            CarbonImmutable::parse($data['to']),
        ), 'Snapshots recalculated');
    }
}

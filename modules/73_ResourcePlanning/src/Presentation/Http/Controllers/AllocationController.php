<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Modules\ResourcePlanning\Application\Commands\AllocateResourceToProjectCommand;
use Modules\ResourcePlanning\Application\Commands\ConvertSoftToHardBookingCommand;
use Modules\ResourcePlanning\Application\Commands\RebalanceResourceOverloadCommand;
use Modules\ResourcePlanning\Application\Handlers\AllocateResourceToProjectHandler;
use Modules\ResourcePlanning\Application\Handlers\ConvertSoftToHardBookingHandler;
use Modules\ResourcePlanning\Application\Handlers\DeleteAllocationHandler;
use Modules\ResourcePlanning\Application\Handlers\RebalanceResourceOverloadHandler;
use Modules\ResourcePlanning\Application\Services\AllocationPresenter;
use Modules\ResourcePlanning\Domain\Enums\BookingType;
use Modules\ResourcePlanning\Domain\Enums\RebalanceStrategy;
use Modules\ResourcePlanning\Presentation\Http\Controllers\Concerns\MapsResourcePlanningErrors;
use Modules\ResourcePlanning\Presentation\Http\Requests\AllocateResourceRequest;
use Modules\ResourcePlanning\Presentation\Http\Requests\ConvertSoftToHardRequest;
use Modules\ResourcePlanning\Presentation\Http\Requests\PlanningWindowRequest;
use Modules\ResourcePlanning\Presentation\Http\Requests\RebalanceAllocationRequest;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Soft (tentative) and hard (committed) bookings of employees on projects and WBS nodes (Mod 079), with the drag-and-drop
 * rebalancing the planning board sends.
 */
final class AllocationController extends Controller
{
    use ApiResponse;
    use MapsResourcePlanningErrors;

    public function __construct(private readonly TenantContext $tenantContext, private readonly AllocationPresenter $presenter) {}

    #[OA\Get(path: '/api/v1/resource-planning/allocations', operationId: 'listResourceAllocations', summary: 'Allocations in a window', description: 'The allocations that overlap the window, with the overload flag and the peak load of each.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Resource Planning'], responses: [new OA\Response(response: 200, description: 'The allocations')])]
    public function index(PlanningWindowRequest $request): JsonResponse
    {
        $employees = $request->validated('employee_id');
        $type = $request->validated('booking_type');

        return $this->successResponse($this->presenter->listing(
            $request->validated('from') === null ? null : CarbonImmutable::parse((string) $request->validated('from')),
            $request->validated('to') === null ? null : CarbonImmutable::parse((string) $request->validated('to')),
            is_array($employees) && $employees !== [] ? (string) $employees[0] : null,
            $request->validated('project_id'),
            is_string($type) ? BookingType::from($type) : null,
        ));
    }

    #[OA\Post(path: '/api/v1/resource-planning/allocations', operationId: 'allocateResource', summary: 'Allocate an employee to a project or an opportunity', description: 'A committed booking needs an allocatable project (Mod 078); a tentative one a project or an open CRM opportunity (Mod 035). The answer carries the overload left in the touched weeks and the warnings (lapsed skills or certificates of Mod 099).', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Resource Planning'], responses: [new OA\Response(response: 201, description: 'The allocation and its overload report'), new OA\Response(response: 422, description: 'Rule violation')])]
    public function store(AllocateResourceRequest $request, AllocateResourceToProjectHandler $handler): JsonResponse
    {
        $data = $request->validated();

        return $this->guarded(fn (): JsonResponse => $this->successResponse($this->presenter->outcome($handler->handle(new AllocateResourceToProjectCommand(
            $this->tenantId(),
            $this->userId(),
            $data['employee_id'],
            BookingType::from($data['booking_type']),
            CarbonImmutable::parse($data['start_date']),
            CarbonImmutable::parse($data['end_date']),
            (float) $data['allocated_hours_per_day'],
            $data['project_id'] ?? null,
            $data['wbs_node_id'] ?? null,
            $data['role_id'] ?? null,
            (bool) ($data['is_billable'] ?? true),
            $data['crm_opportunity_id'] ?? null,
            $data['notes'] ?? null,
            (bool) ($data['allow_overload'] ?? true),
            array_values($data['required_skill_ids'] ?? []),
        ))), 'Resource allocated', Response::HTTP_CREATED));
    }

    #[OA\Post(path: '/api/v1/resource-planning/allocations/{id}/convert-to-hard', operationId: 'convertAllocationToHard', summary: 'Commit a tentative booking', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Resource Planning'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The committed allocation'), new OA\Response(response: 422, description: 'Rule violation')])]
    public function convert(string $id, ConvertSoftToHardRequest $request, ConvertSoftToHardBookingHandler $handler): JsonResponse
    {
        $data = $request->validated();

        return $this->guarded(fn (): JsonResponse => $this->successResponse($this->presenter->outcome($handler->handle(new ConvertSoftToHardBookingCommand(
            $this->tenantId(), $id, $data['project_id'] ?? null, $data['wbs_node_id'] ?? null,
        ))), 'Booking committed'));
    }

    #[OA\Post(path: '/api/v1/resource-planning/allocations/{id}/rebalance', operationId: 'rebalanceAllocation', summary: 'Shift, reassign, split or resize an allocation', description: 'Drag and drop on the planning board: shift moves the allocation in time (or to a colleague), reassign hands it over, split cuts it at a date, resize changes its dates or hours. A rebalance is never refused for overload.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Resource Planning'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Every affected allocation and the remaining overload'), new OA\Response(response: 422, description: 'Rule violation')])]
    public function rebalance(string $id, RebalanceAllocationRequest $request, RebalanceResourceOverloadHandler $handler): JsonResponse
    {
        $data = $request->validated();

        return $this->guarded(fn (): JsonResponse => $this->successResponse($this->presenter->outcome($handler->handle(new RebalanceResourceOverloadCommand(
            $this->tenantId(),
            $id,
            RebalanceStrategy::from($data['strategy']),
            $data['employee_id'] ?? null,
            isset($data['start_date']) ? CarbonImmutable::parse($data['start_date']) : null,
            isset($data['end_date']) ? CarbonImmutable::parse($data['end_date']) : null,
            isset($data['split_date']) ? CarbonImmutable::parse($data['split_date']) : null,
            isset($data['shift_days']) ? (int) $data['shift_days'] : null,
            isset($data['allocated_hours_per_day']) ? (float) $data['allocated_hours_per_day'] : null,
        ))), 'Allocation rebalanced'));
    }

    #[OA\Delete(path: '/api/v1/resource-planning/allocations/{id}', operationId: 'deleteResourceAllocation', summary: 'Delete an allocation', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Resource Planning'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 204, description: 'Deleted'), new OA\Response(response: 404, description: 'Unknown allocation')])]
    public function destroy(string $id, DeleteAllocationHandler $handler): JsonResponse
    {
        return $this->guarded(function () use ($id, $handler): JsonResponse {
            $handler->handle($id);

            return response()->json(null, Response::HTTP_NO_CONTENT);
        });
    }

    private function tenantId(): string
    {
        return (string) $this->tenantContext->getTenantId();
    }

    private function userId(): ?string
    {
        $id = auth()->id();

        return $id === null ? null : (string) $id;
    }
}

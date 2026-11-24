<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\MrpPlanning\Application\Commands\AutoLevelFiniteCapacityScheduleCommand;
use Modules\MrpPlanning\Application\Commands\ExecuteMrpPlanningRunCommand;
use Modules\MrpPlanning\Application\Commands\FirmAndReleaseMrpProposalsCommand;
use Modules\MrpPlanning\Application\Commands\RescheduleGanttBlockCommand;
use Modules\MrpPlanning\Application\Handlers\AutoLevelFiniteCapacityScheduleHandler;
use Modules\MrpPlanning\Application\Handlers\ExecuteMrpPlanningRunHandler;
use Modules\MrpPlanning\Application\Handlers\FirmAndReleaseMrpProposalsHandler;
use Modules\MrpPlanning\Application\Handlers\GetGanttCapacityScheduleHandler;
use Modules\MrpPlanning\Application\Handlers\GetMrpPeggingTreeHandler;
use Modules\MrpPlanning\Application\Handlers\GetMrpRunSummaryHandler;
use Modules\MrpPlanning\Application\Handlers\ListMrpProposalsHandler;
use Modules\MrpPlanning\Application\Handlers\ListMrpRunsHandler;
use Modules\MrpPlanning\Application\Handlers\RescheduleGanttBlockHandler;
use Modules\MrpPlanning\Application\Queries\GetGanttCapacityScheduleQuery;
use Modules\MrpPlanning\Application\Queries\GetMrpPeggingTreeQuery;
use Modules\MrpPlanning\Application\Queries\GetMrpRunSummaryQuery;
use Modules\MrpPlanning\Application\Queries\ListMrpProposalsQuery;
use Modules\MrpPlanning\Application\Queries\ListMrpRunsQuery;
use Modules\MrpPlanning\Domain\Enums\CapacitySchedulingMode;
use Modules\MrpPlanning\Domain\Enums\MrpRunMode;
use Modules\MrpPlanning\Domain\Enums\MrpRunStatus;
use Modules\MrpPlanning\Domain\Enums\ReleaseAction;
use Modules\MrpPlanning\Domain\Enums\SchedulingDirection;
use Modules\MrpPlanning\Domain\Exceptions\MrpException;
use Modules\MrpPlanning\Domain\Exceptions\MrpNotFoundException;
use Modules\MrpPlanning\Presentation\Http\Requests\AutoLevelCapacityRequest;
use Modules\MrpPlanning\Presentation\Http\Requests\ExecuteMrpRunRequest;
use Modules\MrpPlanning\Presentation\Http\Requests\FirmReleaseProposalsRequest;
use Modules\MrpPlanning\Presentation\Http\Requests\GanttScheduleRequest;
use Modules\MrpPlanning\Presentation\Http\Requests\ListMrpProposalsRequest;
use Modules\MrpPlanning\Presentation\Http\Requests\ListMrpRunsRequest;
use Modules\MrpPlanning\Presentation\Http\Requests\RescheduleBlockRequest;
use Modules\MrpPlanning\Presentation\Http\Resources\MrpProposalResource;
use Modules\MrpPlanning\Presentation\Http\Resources\MrpRunResource;
use Modules\MrpPlanning\Presentation\Http\Resources\MrpScheduleBlockResource;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * MRP I & II (Mod 063): runs, proposals workbench, pegging and the finite-capacity Gantt. The controller only turns requests
 * into commands and queries and results into resources; the rules live in the handlers and the domain services.
 */
final class MrpController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TenantContext $tenantContext) {}

    #[OA\Get(path: '/api/v1/mrp/runs', operationId: 'listMrpRuns', summary: 'List MRP runs', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['MRP'], responses: [new OA\Response(response: 200, description: 'A page of runs')])]
    public function runs(ListMrpRunsRequest $request, ListMrpRunsHandler $handler): JsonResponse
    {
        $page = $handler->handle(new ListMrpRunsQuery($this->tenantId(), (int) $request->input('page', 1), (int) $request->input('per_page', 25), $request->filled('status') ? (string) $request->input('status') : null));

        return $this->paginated($page, static fn ($run): array => (new MrpRunResource($run))->resolve());
    }

    #[OA\Post(path: '/api/v1/mrp/runs', operationId: 'executeMrpRun', summary: 'Execute an MRP planning run', description: 'Runs low-level coding, gross-to-net netting, lot sizing, pegging, exception detection and the initial capacity buckets. A cyclic BOM ends the run as Failed.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['MRP'], responses: [new OA\Response(response: 201, description: 'The finished run')])]
    public function execute(ExecuteMrpRunRequest $request, ExecuteMrpPlanningRunHandler $handler): JsonResponse
    {
        return $this->guarded(function () use ($request, $handler): JsonResponse {
            $run = $handler->handle(new ExecuteMrpPlanningRunCommand(
                tenantId: $this->tenantId(),
                planningHorizonDays: (int) $request->input('planning_horizon_days'),
                runMode: MrpRunMode::from((string) $request->input('run_mode', MrpRunMode::RegenerativeFull->value)),
                capacitySchedulingMode: CapacitySchedulingMode::from((string) $request->input('capacity_scheduling_mode', CapacitySchedulingMode::InfiniteCapacity->value)),
                schedulingDirection: SchedulingDirection::from((string) $request->input('scheduling_direction', SchedulingDirection::BackwardFromDueDate->value)),
                includeCrossBorderCustomsLeadTime: $request->boolean('include_cross_border_customs_lead_time'),
                applySupplierShutdownCalendars: $request->boolean('apply_supplier_shutdown_calendars'),
                userId: $this->userId(),
            ));

            return $this->successResponse(new MrpRunResource($run), $run->status === MrpRunStatus::Failed ? 'The run failed' : 'Operation successful', Response::HTTP_CREATED);
        });
    }

    #[OA\Get(path: '/api/v1/mrp/runs/{id}', operationId: 'getMrpRunSummary', summary: 'MRP run summary with KPIs', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['MRP'], responses: [new OA\Response(response: 200, description: 'The run and its KPIs')])]
    public function show(string $id, GetMrpRunSummaryHandler $handler): JsonResponse
    {
        return $this->guarded(function () use ($id, $handler): JsonResponse {
            $summary = $handler->handle(new GetMrpRunSummaryQuery($this->tenantId(), $id));

            return $this->successResponse(['run' => new MrpRunResource($summary['run']), 'kpis' => $summary['kpis']]);
        });
    }

    #[OA\Post(path: '/api/v1/mrp/runs/{id}/auto-level', operationId: 'autoLevelMrpCapacity', summary: 'Auto-level the finite capacity schedule of a run', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['MRP'], responses: [new OA\Response(response: 200, description: 'The overloads before and after leveling')])]
    public function autoLevel(string $id, AutoLevelCapacityRequest $request, AutoLevelFiniteCapacityScheduleHandler $handler): JsonResponse
    {
        return $this->guarded(function () use ($id, $request, $handler): JsonResponse {
            $result = $handler->handle(new AutoLevelFiniteCapacityScheduleCommand($this->tenantId(), $id, $request->filled('scheduling_direction') ? SchedulingDirection::from((string) $request->input('scheduling_direction')) : null));

            return $this->successResponse([
                'run' => new MrpRunResource($result['run']),
                'overloaded_buckets_before' => $result['overloaded_buckets_before'],
                'overloaded_buckets_after' => $result['overloaded_buckets_after'],
                'leveled_proposals' => $result['leveled_proposals'],
            ]);
        });
    }

    #[OA\Get(path: '/api/v1/mrp/runs/{id}/gantt', operationId: 'getMrpGanttSchedule', summary: 'Gantt schedule and capacity buckets of a run', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['MRP'], responses: [new OA\Response(response: 200, description: 'Work centres with buckets and blocks')])]
    public function gantt(string $id, GanttScheduleRequest $request, GetGanttCapacityScheduleHandler $handler): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse($handler->handle(new GetGanttCapacityScheduleQuery(
            $this->tenantId(), $id, CarbonImmutable::parse((string) $request->input('from')), CarbonImmutable::parse((string) $request->input('to')),
            array_values(array_map('strval', (array) $request->input('work_center_ids', []))),
        ))));
    }

    #[OA\Get(path: '/api/v1/mrp/proposals', operationId: 'listMrpProposals', summary: 'List MRP action proposals', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['MRP'], responses: [new OA\Response(response: 200, description: 'A page of proposals')])]
    public function proposals(ListMrpProposalsRequest $request, ListMrpProposalsHandler $handler): JsonResponse
    {
        return $this->guarded(function () use ($request, $handler): JsonResponse {
            $page = $handler->handle(new ListMrpProposalsQuery(
                tenantId: $this->tenantId(),
                runId: $request->filled('run_id') ? (string) $request->input('run_id') : null,
                proposalType: $request->filled('proposal_type') ? (string) $request->input('proposal_type') : null,
                status: $request->filled('status') ? (string) $request->input('status') : null,
                exceptionCode: $request->filled('exception_code') ? (string) $request->input('exception_code') : null,
                itemId: $request->filled('item_id') ? (string) $request->input('item_id') : null,
                workCenterId: $request->filled('work_center_id') ? (string) $request->input('work_center_id') : null,
                needByFrom: $request->filled('need_by_from') ? CarbonImmutable::parse((string) $request->input('need_by_from')) : null,
                needByTo: $request->filled('need_by_to') ? CarbonImmutable::parse((string) $request->input('need_by_to')) : null,
                sortBy: (string) $request->input('sort_by', 'need_by_date'),
                sortDirection: (string) $request->input('sort_direction', 'asc'),
                page: (int) $request->input('page', 1),
                perPage: (int) $request->input('per_page', 25),
            ));

            return $this->paginated($page, static fn ($proposal): array => (new MrpProposalResource($proposal))->resolve());
        });
    }

    #[OA\Get(path: '/api/v1/mrp/proposals/{id}/pegging', operationId: 'getMrpPeggingTree', summary: 'Multi-level pegging tree of a proposal', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['MRP'], responses: [new OA\Response(response: 200, description: 'Upstream demand and downstream components')])]
    public function pegging(string $id, GetMrpPeggingTreeHandler $handler): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse($handler->handle(new GetMrpPeggingTreeQuery($this->tenantId(), $id))));
    }

    #[OA\Post(path: '/api/v1/mrp/proposals/actions', operationId: 'firmAndReleaseMrpProposals', summary: 'Firm, release or dismiss proposals', description: 'Releasing creates purchase requisitions (Mod 044) and production orders (Mod 064), all or nothing.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['MRP'], responses: [new OA\Response(response: 200, description: 'The processed proposals and the documents created')])]
    public function act(FirmReleaseProposalsRequest $request, FirmAndReleaseMrpProposalsHandler $handler): JsonResponse
    {
        return $this->guarded(function () use ($request, $handler): JsonResponse {
            $result = $handler->handle(new FirmAndReleaseMrpProposalsCommand(
                $this->tenantId(), array_values(array_map('strval', (array) $request->input('proposal_ids'))), ReleaseAction::from((string) $request->input('action')), $this->userId(),
            ));

            return $this->successResponse([
                'action' => $result['action'],
                'processed' => $result['processed'],
                'purchase_requisition_ids' => $result['purchase_requisition_ids'],
                'production_order_ids' => $result['production_order_ids'],
                'proposals' => MrpProposalResource::collection(collect($result['proposals'])),
            ]);
        });
    }

    #[OA\Post(path: '/api/v1/mrp/schedule-blocks/{id}/reschedule', operationId: 'rescheduleMrpGanttBlock', summary: 'Reschedule a Gantt block', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['MRP'], responses: [new OA\Response(response: 200, description: 'The moved block and the recomputed load')])]
    public function reschedule(string $id, RescheduleBlockRequest $request, RescheduleGanttBlockHandler $handler): JsonResponse
    {
        return $this->guarded(function () use ($id, $request, $handler): JsonResponse {
            $result = $handler->handle(new RescheduleGanttBlockCommand(
                $this->tenantId(), $id,
                $request->filled('start_date') ? CarbonImmutable::parse((string) $request->input('start_date')) : null,
                $request->filled('end_date') ? CarbonImmutable::parse((string) $request->input('end_date')) : null,
                $request->filled('work_center_id') ? (string) $request->input('work_center_id') : null,
            ));

            return $this->successResponse(['block' => new MrpScheduleBlockResource($result['block']), 'proposal' => new MrpProposalResource($result['proposal']), 'capacity' => $result['buckets']]);
        });
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $page
     * @param  Closure(mixed): array<string, mixed>  $map
     */
    private function paginated(LengthAwarePaginator $page, Closure $map): JsonResponse
    {
        return $this->successResponse(
            array_map($map, $page->items()),
            meta: ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        );
    }

    private function tenantId(): string
    {
        return (string) $this->tenantContext->getTenantId();
    }

    private function userId(): ?string
    {
        $user = auth()->user();

        return $user instanceof User ? (string) $user->id : null;
    }

    /**
     * Maps the module exceptions to problem details.
     *
     * @template T
     *
     * @param  Closure(): T  $action
     * @return T|JsonResponse
     */
    private function guarded(Closure $action): mixed
    {
        try {
            return $action();
        } catch (MrpNotFoundException $exception) {
            return $this->problemDetailsResponse('Not Found', $exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (MrpException $exception) {
            return $this->problemDetailsResponse('MRP Rule Violation', $exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}

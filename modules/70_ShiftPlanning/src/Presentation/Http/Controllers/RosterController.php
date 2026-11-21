<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Modules\ShiftPlanning\Application\Commands\AssignOrMoveShiftCommand;
use Modules\ShiftPlanning\Application\Commands\CopyOrGenerateRosterTemplateCommand;
use Modules\ShiftPlanning\Application\Commands\LockRosterPeriodCommand;
use Modules\ShiftPlanning\Application\Commands\PublishRosterAndNotifyEmployeesCommand;
use Modules\ShiftPlanning\Application\Commands\RespondToShiftSwapCommand;
use Modules\ShiftPlanning\Application\Handlers\AssignOrMoveShiftHandler;
use Modules\ShiftPlanning\Application\Handlers\CopyOrGenerateRosterTemplateHandler;
use Modules\ShiftPlanning\Application\Handlers\GetRosterMatrixHandler;
use Modules\ShiftPlanning\Application\Handlers\ListRosterPeriodsHandler;
use Modules\ShiftPlanning\Application\Handlers\ListShiftSwapRequestsHandler;
use Modules\ShiftPlanning\Application\Handlers\LockRosterPeriodHandler;
use Modules\ShiftPlanning\Application\Handlers\PublishRosterAndNotifyEmployeesHandler;
use Modules\ShiftPlanning\Application\Handlers\RespondToShiftSwapHandler;
use Modules\ShiftPlanning\Application\Queries\GetRosterMatrixQuery;
use Modules\ShiftPlanning\Application\Queries\ListRosterPeriodsQuery;
use Modules\ShiftPlanning\Application\Queries\ListShiftSwapRequestsQuery;
use Modules\ShiftPlanning\Application\Services\ShiftTemplateCatalog;
use Modules\ShiftPlanning\Domain\Enums\Jurisdiction;
use Modules\ShiftPlanning\Domain\Enums\PeriodType;
use Modules\ShiftPlanning\Domain\Enums\RotationPattern;
use Modules\ShiftPlanning\Domain\Enums\SwapStatus;
use Modules\ShiftPlanning\Domain\Exceptions\ShiftAccessDeniedException;
use Modules\ShiftPlanning\Presentation\Http\Controllers\Concerns\MapsShiftPlanningErrors;
use Modules\ShiftPlanning\Presentation\Http\Requests\AssignShiftRequest;
use Modules\ShiftPlanning\Presentation\Http\Requests\GenerateRosterRequest;
use Modules\ShiftPlanning\Presentation\Http\Requests\ShiftActionRequest;
use Modules\ShiftPlanning\Presentation\Http\Requests\ShiftQueryRequest;
use Modules\ShiftPlanning\Presentation\Http\Requests\StoreShiftTemplateRequest;
use Modules\ShiftPlanning\Presentation\Http\Requests\SwapDecisionRequest;
use Modules\ShiftPlanning\Presentation\Http\Resources\RosterPeriodResource;
use Modules\ShiftPlanning\Presentation\Http\Resources\ShiftAssignmentResource;
use Modules\ShiftPlanning\Presentation\Http\Resources\ShiftSwapRequestResource;
use Modules\ShiftPlanning\Presentation\Http\Resources\ShiftTemplateResource;
use OpenApi\Attributes as OA;

/**
 * The planner side of the shift planning module (Mod 073): templates, roster periods, the scheduler matrix, assignments and
 * the manager decision on swap requests.
 */
final class RosterController extends Controller
{
    use ApiResponse;
    use MapsShiftPlanningErrors;

    public function __construct(private readonly TenantContext $tenantContext) {}

    #[OA\Get(path: '/api/v1/shift-planning/templates', operationId: 'listShiftTemplates', summary: 'Reusable shift templates', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Shift Planning'], responses: [new OA\Response(response: 200, description: 'The templates')])]
    public function templates(ShiftTemplateCatalog $catalog): JsonResponse
    {
        return $this->successResponse(ShiftTemplateResource::collection($catalog->list($this->tenantId())));
    }

    #[OA\Post(path: '/api/v1/shift-planning/templates', operationId: 'createShiftTemplate', summary: 'Create a shift template', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Shift Planning'], responses: [new OA\Response(response: 201, description: 'The template'), new OA\Response(response: 422, description: 'Invalid template')])]
    public function storeTemplate(StoreShiftTemplateRequest $request, ShiftTemplateCatalog $catalog): JsonResponse
    {
        return $this->successResponse(new ShiftTemplateResource($catalog->save($this->tenantId(), null, $request->validated())), 'Template created', 201);
    }

    #[OA\Put(path: '/api/v1/shift-planning/templates/{id}', operationId: 'updateShiftTemplate', summary: 'Update a shift template', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Shift Planning'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The template'), new OA\Response(response: 404, description: 'Unknown template')])]
    public function updateTemplate(string $id, StoreShiftTemplateRequest $request, ShiftTemplateCatalog $catalog): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse(new ShiftTemplateResource($catalog->save($this->tenantId(), $id, $request->validated())), 'Template updated'));
    }

    #[OA\Get(path: '/api/v1/shift-planning/periods', operationId: 'listRosterPeriods', description: 'The roster periods of the tenant that overlap the window from-to, optionally of one type (weekly or monthly). The scheduler uses it to find the period of the week or month on screen.', summary: 'Roster periods', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Shift Planning'], responses: [new OA\Response(response: 200, description: 'The periods')])]
    public function periods(ShiftQueryRequest $request, ListRosterPeriodsHandler $handler): JsonResponse
    {
        return $this->successResponse(RosterPeriodResource::collection($handler->handle(new ListRosterPeriodsQuery(
            $this->tenantId(),
            $request->filled('period_type') ? PeriodType::from((string) $request->input('period_type')) : null,
            $request->filled('from') ? CarbonImmutable::parse((string) $request->input('from')) : null,
            $request->filled('to') ? CarbonImmutable::parse((string) $request->input('to')) : null,
        ))));
    }

    #[OA\Post(path: '/api/v1/shift-planning/periods/generate', operationId: 'generateRosterPeriod', description: 'Creates a draft weekly or monthly roster from a rotation pattern (5x2, 6x6) or as a copy of a previous period. Generated shifts carry their compliance findings.', summary: 'Generate or copy a roster period', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Shift Planning'], responses: [new OA\Response(response: 201, description: 'The draft period and its counters'), new OA\Response(response: 422, description: 'Invalid request')])]
    public function generate(GenerateRosterRequest $request, CopyOrGenerateRosterTemplateHandler $handler): JsonResponse
    {
        $data = $request->validated();

        return $this->guarded(function () use ($data, $handler): JsonResponse {
            $result = $handler->handle(new CopyOrGenerateRosterTemplateCommand(
                $this->tenantId(),
                $this->userId(),
                $data['location_or_department_id'] ?? null,
                $data['production_line_id'] ?? null,
                PeriodType::from($data['period_type']),
                CarbonImmutable::parse($data['start_date']),
                isset($data['jurisdiction']) ? Jurisdiction::from($data['jurisdiction']) : $this->defaultJurisdiction(),
                isset($data['pattern']) ? RotationPattern::from($data['pattern']) : null,
                $data['employee_ids'] ?? [],
                $data['shift_template_ids'] ?? [],
                $data['source_period_id'] ?? null,
                array_map('intval', $data['open_slots'] ?? []),
            ));

            return $this->successResponse([
                'period' => new RosterPeriodResource($result['period']),
                'created' => $result['created'], 'open' => $result['open'], 'blocking' => $result['blocking'], 'warnings' => $result['warnings'],
            ], 'Roster generated', 201);
        });
    }

    #[OA\Get(path: '/api/v1/shift-planning/periods/{id}/matrix', operationId: 'getRosterMatrix', description: 'The scheduler grid grouped by employee (scheduled against contractual hours) or by role, the open shifts, the coverage heatmap and the labour cost totals.', summary: 'Roster matrix', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Shift Planning'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The matrix'), new OA\Response(response: 404, description: 'Unknown period')])]
    public function matrix(string $id, ShiftQueryRequest $request, GetRosterMatrixHandler $handler): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse($handler->handle(new GetRosterMatrixQuery($this->tenantId(), $id, (string) $request->input('group_by', GetRosterMatrixQuery::GroupByEmployee)))));
    }

    #[OA\Post(path: '/api/v1/shift-planning/assignments', operationId: 'createShiftAssignment', description: 'Creates a shift from a template, assigned to an employee or open. Blocking rules answer 422 with the rule codes; warnings are saved and returned with the recalculated labour cost.', summary: 'Create a shift assignment', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Shift Planning'], responses: [new OA\Response(response: 201, description: 'The assignment and its findings'), new OA\Response(response: 422, description: 'Blocking compliance error')])]
    public function createAssignment(AssignShiftRequest $request, AssignOrMoveShiftHandler $handler): JsonResponse
    {
        return $this->assign(null, $request, $handler, 201);
    }

    #[OA\Patch(path: '/api/v1/shift-planning/assignments/{id}', operationId: 'moveShiftAssignment', description: 'Assigns an open shift or drags a shift to another employee or day, with the same real-time checks.', summary: 'Assign or move a shift', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Shift Planning'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The assignment and its findings'), new OA\Response(response: 422, description: 'Blocking compliance error')])]
    public function moveAssignment(string $id, AssignShiftRequest $request, AssignOrMoveShiftHandler $handler): JsonResponse
    {
        return $this->assign($id, $request, $handler, 200);
    }

    private function assign(?string $assignmentId, AssignShiftRequest $request, AssignOrMoveShiftHandler $handler, int $status): JsonResponse
    {
        $data = $request->validated();

        return $this->guarded(function () use ($assignmentId, $data, $handler, $status): JsonResponse {
            $result = $handler->handle(new AssignOrMoveShiftCommand(
                $this->tenantId(),
                $this->userId(),
                $assignmentId,
                $data['roster_schedule_period_id'] ?? null,
                CarbonImmutable::parse($data['shift_date']),
                $data['shift_template_id'],
                $data['employee_id'] ?? null,
                $data['required_role_or_skill_id'] ?? null,
            ));

            return $this->successResponse([
                'assignment' => new ShiftAssignmentResource($result->assignment->load('template')),
                'warnings' => $result->report?->issuesAsArray() ?? [],
                'estimated_labor_cost' => $result->assignment->estimated_labor_cost,
            ], 'Shift saved', $status);
        });
    }

    #[OA\Post(path: '/api/v1/shift-planning/periods/{id}/publish', operationId: 'publishRoster', description: 'Re-checks every assigned shift, publishes the roster, notifies the employees and sends the surcharge breakdown to time & attendance and payroll.', summary: 'Publish a roster', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Shift Planning'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The published period'), new OA\Response(response: 422, description: 'Blocking compliance issues or not a draft')])]
    public function publish(string $id, ShiftActionRequest $request, PublishRosterAndNotifyEmployeesHandler $handler): JsonResponse
    {
        return $this->guarded(function () use ($id, $handler): JsonResponse {
            $summary = $handler->handle(new PublishRosterAndNotifyEmployeesCommand($this->tenantId(), $this->userId(), $id));

            return $this->successResponse([
                'period' => new RosterPeriodResource($summary['period']),
                'assigned' => $summary['assigned'], 'open' => $summary['open'], 'warnings' => $summary['warnings'],
            ], 'Roster published');
        });
    }

    #[OA\Post(path: '/api/v1/shift-planning/periods/{id}/lock', operationId: 'lockRoster', summary: 'Lock a published roster', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Shift Planning'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The locked period'), new OA\Response(response: 422, description: 'Not a published roster')])]
    public function lock(string $id, ShiftActionRequest $request, LockRosterPeriodHandler $handler): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse(new RosterPeriodResource($handler->handle(new LockRosterPeriodCommand($this->tenantId(), $this->userId(), $id))), 'Roster locked'));
    }

    #[OA\Get(path: '/api/v1/shift-planning/swaps', operationId: 'listShiftSwapsForManager', description: 'The swap inbox of the tenant. Requests waiting for approval carry the compliance impact of both employees, before and after the exchange.', summary: 'Manager swap inbox', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Shift Planning'], responses: [new OA\Response(response: 200, description: 'The swap requests')])]
    public function swaps(ShiftQueryRequest $request, ListShiftSwapRequestsHandler $handler): JsonResponse
    {
        return $this->successResponse($handler->handle(new ListShiftSwapRequestsQuery(
            $this->tenantId(),
            $request->filled('status') ? SwapStatus::from((string) $request->input('status')) : null,
            null,
            true,
        )));
    }

    #[OA\Post(path: '/api/v1/shift-planning/swaps/{id}/decision', operationId: 'decideShiftSwap', description: 'The second step of the swap approval. Approving re-validates both employees atomically; a blocking rule answers 422 and the request stays pending.', summary: 'Approve or reject a swap', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Shift Planning'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The decided swap'), new OA\Response(response: 409, description: 'Already decided'), new OA\Response(response: 422, description: 'Blocking compliance error')])]
    public function decideSwap(string $id, SwapDecisionRequest $request, RespondToShiftSwapHandler $handler): JsonResponse
    {
        $data = $request->validated();

        return $this->guarded(fn (): JsonResponse => $this->successResponse(new ShiftSwapRequestResource($handler->handle(new RespondToShiftSwapCommand(
            $this->tenantId(), $id, true, $data['decision'] === 'approve', null, $this->userId(), $data['rejection_reason'] ?? null,
        ))), 'Swap decided'));
    }

    private function tenantId(): string
    {
        return (string) $this->tenantContext->getTenantId();
    }

    private function userId(): string
    {
        $user = auth()->user();

        return $user instanceof User ? (string) $user->getKey() : throw new ShiftAccessDeniedException('Sign in to plan shifts.');
    }

    private function defaultJurisdiction(): Jurisdiction
    {
        return Jurisdiction::fromCountry($this->tenantContext->getTenant()?->country);
    }
}

<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Manufacturing\Enums\DefectReason;
use Modules\Manufacturing\Enums\MachineMode;
use Modules\Manufacturing\Enums\SuspensionReason;
use Modules\Manufacturing\Http\Controllers\Concerns\InteractsWithManufacturing;
use Modules\Manufacturing\Http\Requests\ClosePhaseRequest;
use Modules\Manufacturing\Http\Requests\ConsumeMaterialLotRequest;
use Modules\Manufacturing\Http\Requests\DeclareProductionRequest;
use Modules\Manufacturing\Http\Requests\OperatorBoardRequest;
use Modules\Manufacturing\Http\Requests\PhaseTransitionRequest;
use Modules\Manufacturing\Http\Requests\StartProductionPhaseRequest;
use Modules\Manufacturing\Http\Requests\SuspendPhaseRequest;
use Modules\Manufacturing\Http\Requests\TakeOverPhaseRequest;
use Modules\Manufacturing\Http\Resources\MesPhaseResource;
use Modules\Manufacturing\Mes\Commands\ClosePhaseCommand;
use Modules\Manufacturing\Mes\Commands\ConsumeMaterialLotCommand;
use Modules\Manufacturing\Mes\Commands\DeclareProductionCommand;
use Modules\Manufacturing\Mes\Commands\ResumePhaseCommand;
use Modules\Manufacturing\Mes\Commands\StartProductionCommand;
use Modules\Manufacturing\Mes\Commands\StartSetupCommand;
use Modules\Manufacturing\Mes\Commands\SuspendPhaseCommand;
use Modules\Manufacturing\Mes\Commands\TakeOverPhaseCommand;
use Modules\Manufacturing\Mes\Handlers\ClosePhaseHandler;
use Modules\Manufacturing\Mes\Handlers\ConsumeMaterialLotHandler;
use Modules\Manufacturing\Mes\Handlers\DeclareProductionHandler;
use Modules\Manufacturing\Mes\Handlers\ResumePhaseHandler;
use Modules\Manufacturing\Mes\Handlers\StartProductionHandler;
use Modules\Manufacturing\Mes\Handlers\StartSetupHandler;
use Modules\Manufacturing\Mes\Handlers\SuspendPhaseHandler;
use Modules\Manufacturing\Mes\Handlers\TakeOverPhaseHandler;
use Modules\Manufacturing\Mes\Queries\GetOperatorBoardHandler;
use Modules\Manufacturing\Mes\Queries\GetOperatorBoardQuery;
use Modules\Manufacturing\Mes\Queries\GetPhaseTraceabilityHandler;
use Modules\Manufacturing\Mes\Queries\GetPhaseTraceabilityQuery;
use Modules\Manufacturing\Models\MfgMesPhase;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgProductionOrderOperation;
use OpenApi\Attributes as OA;

/**
 * MES operator board: the board query, the phase lifecycle commands, declarations and lot consumption. Controllers only
 * authorise, build the command and answer with the phase; rules live in the handlers.
 */
final class MesOperatorBoardController extends Controller
{
    use InteractsWithManufacturing;

    /**
     * The board of a work centre.
     */
    #[OA\Get(path: '/api/v1/manufacturing/mes/board', operationId: 'getMesOperatorBoard', description: 'Phases being worked at the work centre with their allowed actions and server-computed times, plus the open operations nobody took over.', summary: 'Operator board', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing MES'],
        parameters: [new OA\Parameter(name: 'work_center_id', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'operator_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Board'), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function board(OperatorBoardRequest $request, GetOperatorBoardHandler $handler): JsonResponse
    {
        $data = $request->validated();

        return response()->json(['data' => $handler->handle(new GetOperatorBoardQuery($this->tenantId(), (string) $data['work_center_id'], $data['operator_id'] ?? null))]);
    }

    /**
     * Traceability summary of a phase.
     */
    #[OA\Get(path: '/api/v1/manufacturing/mes/phases/{id}/traceability', operationId: 'getMesPhaseTraceability', description: 'Intervals, declarations, scrap reasons and consumed lots of a phase.', summary: 'Phase traceability', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing MES'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        responses: [new OA\Response(response: 200, description: 'Traceability'), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404)])]
    public function traceability(string $id, GetPhaseTraceabilityHandler $handler): JsonResponse
    {
        $this->owned(MfgMesPhase::class, $id);

        return response()->json(['data' => $handler->handle(new GetPhaseTraceabilityQuery($this->tenantId(), $id))]);
    }

    /**
     * Takes an operation over.
     */
    #[OA\Post(path: '/api/v1/manufacturing/mes/phases/take-over', operationId: 'takeOverMesPhase', description: 'Creates the phase of an operation, or hands it over to another operator before work starts or while suspended.', summary: 'Take over a phase', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing MES'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['idempotency_key', 'production_order_operation_id', 'operator_id'], properties: [new OA\Property(property: 'idempotency_key', type: 'string'), new OA\Property(property: 'production_order_operation_id', type: 'string', format: 'uuid'), new OA\Property(property: 'operator_id', type: 'string', format: 'uuid'), new OA\Property(property: 'machine_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'expected_version', type: 'integer', nullable: true), new OA\Property(property: 'occurred_at', type: 'string', format: 'date-time', nullable: true)])),
        responses: [new OA\Response(response: 200, description: 'Phase'), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function takeOver(TakeOverPhaseRequest $request, TakeOverPhaseHandler $handler): MesPhaseResource
    {
        $data = $request->validated();
        $operation = $this->owned(MfgProductionOrderOperation::class, (string) $data['production_order_operation_id']);
        $this->authorize('execute', $this->owned(MfgProductionOrder::class, (string) $operation->production_order_id));

        return new MesPhaseResource($handler->handle(new TakeOverPhaseCommand(
            $this->tenantId(), $this->actor($request), $request->idempotencyKey(), (string) $operation->id, (string) $data['operator_id'], $data['machine_id'] ?? null, $request->expectedVersion(), $request->occurredAt(),
        )));
    }

    /**
     * Starts the setup.
     */
    #[OA\Post(path: '/api/v1/manufacturing/mes/phases/{id}/start-setup', operationId: 'startMesPhaseSetup', description: 'Starts the setup of a taken-over phase.', summary: 'Start setup', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing MES'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['idempotency_key'], properties: [new OA\Property(property: 'idempotency_key', type: 'string'), new OA\Property(property: 'expected_version', type: 'integer', nullable: true), new OA\Property(property: 'occurred_at', type: 'string', format: 'date-time', nullable: true)])),
        responses: [new OA\Response(response: 200, description: 'Phase'), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function startSetup(PhaseTransitionRequest $request, string $id, StartSetupHandler $handler): MesPhaseResource
    {
        $phase = $this->authorizedPhase($id);

        return new MesPhaseResource($handler->handle(new StartSetupCommand($this->tenantId(), $this->actor($request), $request->idempotencyKey(), (string) $phase->id, $request->expectedVersion(), $request->occurredAt())));
    }

    /**
     * Starts machining.
     */
    #[OA\Post(path: '/api/v1/manufacturing/mes/phases/{id}/start-production', operationId: 'startMesPhaseProduction', description: 'Starts machining, attended or unattended; on a running phase it switches the mode.', summary: 'Start production', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing MES'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['idempotency_key'], properties: [new OA\Property(property: 'idempotency_key', type: 'string'), new OA\Property(property: 'machine_mode', type: 'string', enum: ['attended', 'unattended']), new OA\Property(property: 'expected_version', type: 'integer', nullable: true), new OA\Property(property: 'occurred_at', type: 'string', format: 'date-time', nullable: true)])),
        responses: [new OA\Response(response: 200, description: 'Phase'), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function startProduction(StartProductionPhaseRequest $request, string $id, StartProductionHandler $handler): MesPhaseResource
    {
        $phase = $this->authorizedPhase($id);
        $mode = MachineMode::from((string) ($request->validated('machine_mode') ?? MachineMode::Attended->value));

        return new MesPhaseResource($handler->handle(new StartProductionCommand($this->tenantId(), $this->actor($request), $request->idempotencyKey(), (string) $phase->id, $mode, $request->expectedVersion(), $request->occurredAt())));
    }

    /**
     * Suspends the phase.
     */
    #[OA\Post(path: '/api/v1/manufacturing/mes/phases/{id}/suspend', operationId: 'suspendMesPhase', description: 'Suspends a phase in setup or run with a reason code; the time until the resume is downtime.', summary: 'Suspend a phase', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing MES'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['idempotency_key', 'reason'], properties: [new OA\Property(property: 'idempotency_key', type: 'string'), new OA\Property(property: 'reason', type: 'string', enum: ['machine_breakdown', 'material_shortage', 'quality_hold', 'break', 'shift_handover', 'tool_change', 'other']), new OA\Property(property: 'expected_version', type: 'integer', nullable: true), new OA\Property(property: 'occurred_at', type: 'string', format: 'date-time', nullable: true)])),
        responses: [new OA\Response(response: 200, description: 'Phase'), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function suspend(SuspendPhaseRequest $request, string $id, SuspendPhaseHandler $handler): MesPhaseResource
    {
        $phase = $this->authorizedPhase($id);

        return new MesPhaseResource($handler->handle(new SuspendPhaseCommand($this->tenantId(), $this->actor($request), $request->idempotencyKey(), (string) $phase->id, SuspensionReason::from((string) $request->validated('reason')), $request->expectedVersion(), $request->occurredAt())));
    }

    /**
     * Resumes the phase.
     */
    #[OA\Post(path: '/api/v1/manufacturing/mes/phases/{id}/resume', operationId: 'resumeMesPhase', description: 'Resumes a suspended phase in the state it was suspended from.', summary: 'Resume a phase', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing MES'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['idempotency_key'], properties: [new OA\Property(property: 'idempotency_key', type: 'string'), new OA\Property(property: 'expected_version', type: 'integer', nullable: true), new OA\Property(property: 'occurred_at', type: 'string', format: 'date-time', nullable: true)])),
        responses: [new OA\Response(response: 200, description: 'Phase'), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function resume(PhaseTransitionRequest $request, string $id, ResumePhaseHandler $handler): MesPhaseResource
    {
        $phase = $this->authorizedPhase($id);

        return new MesPhaseResource($handler->handle(new ResumePhaseCommand($this->tenantId(), $this->actor($request), $request->idempotencyKey(), (string) $phase->id, $request->expectedVersion(), $request->occurredAt())));
    }

    /**
     * Declares good and scrapped pieces.
     */
    #[OA\Post(path: '/api/v1/manufacturing/mes/phases/{id}/declarations', operationId: 'declareMesProduction', description: 'Declares good and scrapped pieces; scrapped pieces need a defect reason.', summary: 'Declare production', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing MES'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['idempotency_key', 'good_quantity'], properties: [new OA\Property(property: 'idempotency_key', type: 'string'), new OA\Property(property: 'good_quantity', type: 'string'), new OA\Property(property: 'scrap_quantity', type: 'string'), new OA\Property(property: 'defect_reason', type: 'string', enum: ['dimensional', 'surface', 'material_defect', 'tool_wear', 'setup_error', 'operator_error', 'machine_fault', 'other']), new OA\Property(property: 'note', type: 'string', nullable: true), new OA\Property(property: 'expected_version', type: 'integer', nullable: true), new OA\Property(property: 'occurred_at', type: 'string', format: 'date-time', nullable: true)])),
        responses: [new OA\Response(response: 200, description: 'Phase'), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function declareProduction(DeclareProductionRequest $request, string $id, DeclareProductionHandler $handler): MesPhaseResource
    {
        $phase = $this->authorizedPhase($id);
        $data = $request->validated();

        return new MesPhaseResource($handler->handle(new DeclareProductionCommand(
            $this->tenantId(), $this->actor($request), $request->idempotencyKey(), (string) $phase->id, (string) $data['good_quantity'], (string) ($data['scrap_quantity'] ?? '0'),
            isset($data['defect_reason']) ? DefectReason::from((string) $data['defect_reason']) : null, $data['note'] ?? null, $request->expectedVersion(), $request->occurredAt(),
        )));
    }

    /**
     * Registers a consumed lot.
     */
    #[OA\Post(path: '/api/v1/manufacturing/mes/phases/{id}/material-consumptions', operationId: 'consumeMesMaterialLot', description: 'Registers a component consumed from a lot: the stock issue and the lot genealogy follow.', summary: 'Consume a material lot', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing MES'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['idempotency_key', 'mfg_item_id', 'quantity'], properties: [new OA\Property(property: 'idempotency_key', type: 'string'), new OA\Property(property: 'mfg_item_id', type: 'string', format: 'uuid'), new OA\Property(property: 'lot_code', type: 'string', nullable: true), new OA\Property(property: 'quantity', type: 'string'), new OA\Property(property: 'over_consumption_confirmed', type: 'boolean'), new OA\Property(property: 'expected_version', type: 'integer', nullable: true), new OA\Property(property: 'occurred_at', type: 'string', format: 'date-time', nullable: true)])),
        responses: [new OA\Response(response: 200, description: 'Phase'), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function consumeMaterial(ConsumeMaterialLotRequest $request, string $id, ConsumeMaterialLotHandler $handler): MesPhaseResource
    {
        $phase = $this->authorizedPhase($id);
        $data = $request->validated();

        return new MesPhaseResource($handler->handle(new ConsumeMaterialLotCommand(
            $this->tenantId(), $this->actor($request), $request->idempotencyKey(), (string) $phase->id, (string) $data['mfg_item_id'], $data['lot_code'] ?? null, (string) $data['quantity'],
            (bool) ($data['over_consumption_confirmed'] ?? false), $request->expectedVersion(), $request->occurredAt(),
        )));
    }

    /**
     * Closes the phase.
     */
    #[OA\Post(path: '/api/v1/manufacturing/mes/phases/{id}/close', operationId: 'closeMesPhase', description: 'Closes the phase: its times and declared pieces confirm the operation as completed.', summary: 'Close a phase', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing MES'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['idempotency_key'], properties: [new OA\Property(property: 'idempotency_key', type: 'string'), new OA\Property(property: 'posting_date', type: 'string', format: 'date', nullable: true), new OA\Property(property: 'expected_version', type: 'integer', nullable: true), new OA\Property(property: 'occurred_at', type: 'string', format: 'date-time', nullable: true)])),
        responses: [new OA\Response(response: 200, description: 'Phase'), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function close(ClosePhaseRequest $request, string $id, ClosePhaseHandler $handler): MesPhaseResource
    {
        $phase = $this->authorizedPhase($id);

        return new MesPhaseResource($handler->handle(new ClosePhaseCommand($this->tenantId(), $this->actor($request), $request->idempotencyKey(), (string) $phase->id, $request->validated('posting_date'), $request->expectedVersion(), $request->occurredAt())));
    }

    /**
     * The phase of the company, on an order the user may execute.
     */
    private function authorizedPhase(string $id): MfgMesPhase
    {
        $phase = $this->owned(MfgMesPhase::class, $id);
        $this->authorize('execute', $this->owned(MfgProductionOrder::class, (string) $phase->production_order_id));

        return $phase;
    }
}

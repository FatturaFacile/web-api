<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Modules\TimeTravel\Application\Commands\GenerateCheckpointsCommand;
use Modules\TimeTravel\Application\Commands\GenerateCheckpointsHandler;
use Modules\TimeTravel\Application\Commands\RunWhatIfValuationReplayCommand;
use Modules\TimeTravel\Application\Commands\RunWhatIfValuationReplayHandler;
use Modules\TimeTravel\Application\Queries\CompareBiTemporalCutOffDiffHandler;
use Modules\TimeTravel\Application\Queries\CompareBiTemporalCutOffDiffQuery;
use Modules\TimeTravel\Application\Queries\ReconstructStateAtBiTemporalCoordinateHandler;
use Modules\TimeTravel\Application\Queries\ReconstructStateAtBiTemporalCoordinateQuery;
use Modules\TimeTravel\Application\Queries\VerifyMerkleAuditChainHandler;
use Modules\TimeTravel\Application\Queries\VerifyMerkleAuditChainQuery;
use Modules\TimeTravel\Http\Requests\AuditIntegrityRequest;
use Modules\TimeTravel\Http\Requests\CutOffDiffRequest;
use Modules\TimeTravel\Http\Requests\GenerateCheckpointsRequest;
use Modules\TimeTravel\Http\Requests\StateRequest;
use Modules\TimeTravel\Http\Requests\WhatIfReplayRequest;
use Modules\TimeTravel\Http\Resources\CheckpointResource;
use Modules\TimeTravel\Http\Resources\CutOffDiffResource;
use Modules\TimeTravel\Http\Resources\IntegrityProofResource;
use Modules\TimeTravel\Http\Resources\StateResource;
use Modules\TimeTravel\Http\Resources\WhatIfRunResource;
use OpenApi\Attributes as OA;

/**
 * Bi-temporal time travel (Mod 354): the books as they were economically at a date (valid time) and as they were known at a moment
 * (known time), the diff of a cut-off between two moments of knowledge, the what-if valuation replay and the integrity certificate.
 */
final class TimeTravelController extends Controller
{
    use ApiResponse;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    public function __construct(private readonly TenantContext $tenants) {}

    #[OA\Get(path: '/api/v1/time-travel/state', operationId: 'timeTravelState', summary: 'Trial balance, account ledger, aging, inventory or fixed assets reconstructed at (valid_at, known_at)', security: self::SECURITY, tags: ['Time travel'], responses: [new OA\Response(response: 200, description: 'State'), new OA\Response(response: 422, description: 'Invalid coordinate')])]
    public function state(StateRequest $request, ReconstructStateAtBiTemporalCoordinateHandler $handler): JsonResponse
    {
        $state = $handler->handle(new ReconstructStateAtBiTemporalCoordinateQuery($this->tenantId(), $request->domain(), $request->coordinate(), $request->filters()));

        return $this->successResponse(new StateResource($state), __('time_travel.messages.state'), 200, [
            'event_count' => $state['event_count'],
            'checkpoint_used' => $state['checkpoint_used'],
            'duration_ms' => $state['duration_ms'],
        ]);
    }

    #[OA\Get(path: '/api/v1/time-travel/cut-off-diff', operationId: 'timeTravelCutOffDiff', summary: 'Difference of a cut-off between two moments of knowledge, with the backdated entries', security: self::SECURITY, tags: ['Time travel'], responses: [new OA\Response(response: 200, description: 'Diff'), new OA\Response(response: 422, description: 'Invalid coordinates')])]
    public function cutOffDiff(CutOffDiffRequest $request, CompareBiTemporalCutOffDiffHandler $handler): JsonResponse
    {
        $diff = $handler->handle(new CompareBiTemporalCutOffDiffQuery(
            $this->tenantId(),
            $request->domain(),
            $request->instant('valid_at'),
            $request->instant('known_at_from'),
            $request->instant('known_at_to'),
            $request->filters(),
        ));

        return $this->successResponse(new CutOffDiffResource($diff), __('time_travel.messages.diff'));
    }

    #[OA\Post(path: '/api/v1/time-travel/what-if-replay', operationId: 'timeTravelWhatIfReplay', summary: 'Replays the inventory stream under another valuation method (WAC, FIFO, LIFO) without touching the books', security: self::SECURITY, tags: ['Time travel'], responses: [new OA\Response(response: 201, description: 'Simulation run'), new OA\Response(response: 422, description: 'Invalid input')])]
    public function whatIfReplay(WhatIfReplayRequest $request, RunWhatIfValuationReplayHandler $handler): JsonResponse
    {
        $user = $request->user();

        $run = $handler->handle(new RunWhatIfValuationReplayCommand(
            $this->tenantId(),
            $request->coordinate(),
            $request->method(),
            $request->optional('warehouse_id'),
            $request->optional('item_id'),
            $request->optional('simulation_name'),
            $user !== null ? (string) $user->getAuthIdentifier() : null,
        ));

        return $this->createdResponse(new WhatIfRunResource($run), __('time_travel.messages.whatif'));
    }

    #[OA\Get(path: '/api/v1/time-travel/audit-integrity', operationId: 'timeTravelAuditIntegrity', summary: 'Recomputes the SHA-256 hash chains and the Merkle roots of a range of dates (GeBüV / Art. 957a CO certificate)', security: self::SECURITY, tags: ['Time travel'], responses: [new OA\Response(response: 200, description: 'Integrity proof'), new OA\Response(response: 422, description: 'Invalid range')])]
    public function auditIntegrity(AuditIntegrityRequest $request, VerifyMerkleAuditChainHandler $handler): JsonResponse
    {
        $proof = $handler->handle(new VerifyMerkleAuditChainQuery($this->tenantId(), $request->from(), $request->to()));

        return $this->successResponse(new IntegrityProofResource($proof), $proof->verified ? __('time_travel.messages.integrity_verified') : __('time_travel.messages.integrity_broken'));
    }

    #[OA\Post(path: '/api/v1/time-travel/checkpoints/generate', operationId: 'timeTravelCheckpointsGenerate', summary: 'Materializes the state checkpoints of the event streams now', security: self::SECURITY, tags: ['Time travel'], responses: [new OA\Response(response: 201, description: 'Checkpoints'), new OA\Response(response: 422, description: 'Invalid input')])]
    public function generateCheckpoints(GenerateCheckpointsRequest $request, GenerateCheckpointsHandler $handler): JsonResponse
    {
        $checkpoints = $handler->handle(new GenerateCheckpointsCommand($this->tenantId(), $request->aggregateType()));

        return $this->createdResponse(['checkpoints' => CheckpointResource::collection($checkpoints)->resolve()], __('time_travel.messages.checkpoints'));
    }

    private function tenantId(): string
    {
        return (string) $this->tenants->getTenantId();
    }
}

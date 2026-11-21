<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Modules\HumanResources\Models\Employee;
use Modules\ShiftPlanning\Application\Commands\ClaimOpenShiftOrRequestPeerSwapCommand;
use Modules\ShiftPlanning\Application\Commands\RespondToShiftSwapCommand;
use Modules\ShiftPlanning\Application\Handlers\ClaimOpenShiftOrRequestPeerSwapHandler;
use Modules\ShiftPlanning\Application\Handlers\GetEmployeeShiftsHandler;
use Modules\ShiftPlanning\Application\Handlers\GetTeamShiftsHandler;
use Modules\ShiftPlanning\Application\Handlers\ListEligibleSwapPeersHandler;
use Modules\ShiftPlanning\Application\Handlers\ListShiftSwapRequestsHandler;
use Modules\ShiftPlanning\Application\Handlers\RespondToShiftSwapHandler;
use Modules\ShiftPlanning\Application\Queries\GetEmployeeShiftsQuery;
use Modules\ShiftPlanning\Application\Queries\GetTeamShiftsQuery;
use Modules\ShiftPlanning\Application\Queries\ListEligibleSwapPeersQuery;
use Modules\ShiftPlanning\Application\Queries\ListShiftSwapRequestsQuery;
use Modules\ShiftPlanning\Domain\Enums\ClaimMode;
use Modules\ShiftPlanning\Domain\Enums\SwapStatus;
use Modules\ShiftPlanning\Domain\Exceptions\ShiftAccessDeniedException;
use Modules\ShiftPlanning\Presentation\Http\Controllers\Concerns\MapsShiftPlanningErrors;
use Modules\ShiftPlanning\Presentation\Http\Requests\PeerSwapResponseRequest;
use Modules\ShiftPlanning\Presentation\Http\Requests\RequestShiftSwapRequest;
use Modules\ShiftPlanning\Presentation\Http\Requests\ShiftActionRequest;
use Modules\ShiftPlanning\Presentation\Http\Requests\ShiftQueryRequest;
use Modules\ShiftPlanning\Presentation\Http\Resources\ShiftAssignmentResource;
use Modules\ShiftPlanning\Presentation\Http\Resources\ShiftSwapRequestResource;
use OpenApi\Attributes as OA;

/**
 * The employee side of the shift planning module (Mod 073): the personal agenda, the open shifts marketplace and the peer
 * swaps. Every action is the one of the employee record linked to the signed-in user.
 */
final class EmployeeShiftController extends Controller
{
    use ApiResponse;
    use MapsShiftPlanningErrors;

    public function __construct(private readonly TenantContext $tenantContext) {}

    #[OA\Get(path: '/api/v1/shift-planning/my/shifts', operationId: 'listMyShifts', description: 'The published shifts of the signed-in employee in a date window (default: the next 30 days), with the surcharge tags (night, Sunday, holiday, on call).', summary: 'My published shifts', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Shift Planning'], responses: [new OA\Response(response: 200, description: 'The shifts'), new OA\Response(response: 403, description: 'No employee record')])]
    public function myShifts(ShiftQueryRequest $request, GetEmployeeShiftsHandler $handler): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse($handler->handle($this->shiftsQuery($request, false))));
    }

    #[OA\Get(path: '/api/v1/shift-planning/my/team-shifts', operationId: 'listTeamShifts', description: 'The published shifts of the colleagues who share a roster with the employee in a date window: name, template and time only, no cost.', summary: 'Team calendar', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Shift Planning'], responses: [new OA\Response(response: 200, description: 'The colleagues\' shifts')])]
    public function teamShifts(ShiftQueryRequest $request, GetTeamShiftsHandler $handler): JsonResponse
    {
        return $this->guarded(function () use ($request, $handler): JsonResponse {
            $from = $request->filled('from') ? CarbonImmutable::parse((string) $request->input('from')) : CarbonImmutable::today();
            $to = $request->filled('to') ? CarbonImmutable::parse((string) $request->input('to')) : $from->addDays(30);

            return $this->successResponse($handler->handle(new GetTeamShiftsQuery($this->tenantId(), $this->employeeId(), $from, $to)));
        });
    }

    #[OA\Get(path: '/api/v1/shift-planning/my/shifts/{id}/eligible-peers', operationId: 'listEligibleSwapPeers', description: 'The colleagues that could take the shift without breaking a blocking rule, with their upcoming shifts for a one-to-one exchange.', summary: 'Colleagues eligible for a swap', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Shift Planning'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The eligible colleagues'), new OA\Response(response: 403, description: 'Not your shift')])]
    public function eligiblePeers(string $id, ListEligibleSwapPeersHandler $handler): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse($handler->handle(new ListEligibleSwapPeersQuery($this->tenantId(), $this->employeeId(), $id))));
    }

    #[OA\Get(path: '/api/v1/shift-planning/my/open-shifts', operationId: 'listOpenShifts', description: 'The open shifts of published rosters that the employee could claim: skills, certificates, leave and rest rules all pass.', summary: 'Open shifts marketplace', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Shift Planning'], responses: [new OA\Response(response: 200, description: 'The claimable open shifts')])]
    public function openShifts(ShiftQueryRequest $request, GetEmployeeShiftsHandler $handler): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse($handler->handle($this->shiftsQuery($request, true))));
    }

    #[OA\Post(path: '/api/v1/shift-planning/my/open-shifts/{id}/claim', operationId: 'claimOpenShift', summary: 'Claim an open shift', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Shift Planning'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The claimed shift and its warnings'), new OA\Response(response: 409, description: 'Already taken'), new OA\Response(response: 422, description: 'Blocking compliance error')])]
    public function claim(string $id, ShiftActionRequest $request, ClaimOpenShiftOrRequestPeerSwapHandler $handler): JsonResponse
    {
        return $this->guarded(function () use ($id, $handler): JsonResponse {
            $result = $handler->handle(new ClaimOpenShiftOrRequestPeerSwapCommand($this->tenantId(), $this->employeeId(), ClaimMode::Claim, $id));

            return $this->successResponse(['assignment' => new ShiftAssignmentResource($result['assignment']->load('template')), 'warnings' => $result['warnings']], 'Shift claimed');
        });
    }

    #[OA\Post(path: '/api/v1/shift-planning/my/swaps', operationId: 'requestShiftSwap', description: 'Offers an own shift to a colleague (optionally against one of the colleague\'s shifts) or, without a colleague, to every qualified colleague.', summary: 'Request a peer shift swap', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Shift Planning'], responses: [new OA\Response(response: 201, description: 'The swap request'), new OA\Response(response: 422, description: 'Invalid swap')])]
    public function requestSwap(RequestShiftSwapRequest $request, ClaimOpenShiftOrRequestPeerSwapHandler $handler): JsonResponse
    {
        $data = $request->validated();

        return $this->guarded(function () use ($data, $handler): JsonResponse {
            $result = $handler->handle(new ClaimOpenShiftOrRequestPeerSwapCommand(
                $this->tenantId(), $this->employeeId(), ClaimMode::Swap, $data['shift_assignment_id'], $data['target_peer_employee_id'] ?? null, $data['target_shift_assignment_id'] ?? null,
            ));

            return $this->successResponse(new ShiftSwapRequestResource($result['swap']), 'Swap requested', 201);
        });
    }

    #[OA\Get(path: '/api/v1/shift-planning/my/swaps', operationId: 'listMyShiftSwaps', description: 'The swaps the employee requested, those offered to the employee and the broadcasts waiting for a colleague.', summary: 'My swap requests', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Shift Planning'], responses: [new OA\Response(response: 200, description: 'The swap requests')])]
    public function mySwaps(ShiftQueryRequest $request, ListShiftSwapRequestsHandler $handler): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse($handler->handle(new ListShiftSwapRequestsQuery(
            $this->tenantId(),
            $request->filled('status') ? SwapStatus::from((string) $request->input('status')) : null,
            $this->employeeId(),
        ))));
    }

    #[OA\Post(path: '/api/v1/shift-planning/my/swaps/{id}/respond', operationId: 'respondToShiftSwap', description: 'The first step of the swap approval: the colleague accepts (approve) or declines (reject). Accepting is checked against the colleague\'s own plan.', summary: 'Accept or decline a peer swap', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Shift Planning'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The swap'), new OA\Response(response: 422, description: 'Blocking compliance error')])]
    public function respond(string $id, PeerSwapResponseRequest $request, RespondToShiftSwapHandler $handler): JsonResponse
    {
        $data = $request->validated();

        return $this->guarded(fn (): JsonResponse => $this->successResponse(new ShiftSwapRequestResource($handler->handle(new RespondToShiftSwapCommand(
            $this->tenantId(), $id, false, $data['decision'] === 'approve', $this->employeeId(),
        ))), 'Answer recorded'));
    }

    private function shiftsQuery(ShiftQueryRequest $request, bool $open): GetEmployeeShiftsQuery
    {
        $from = $request->filled('from') ? CarbonImmutable::parse((string) $request->input('from')) : CarbonImmutable::today();
        $to = $request->filled('to') ? CarbonImmutable::parse((string) $request->input('to')) : $from->addDays(30);

        return new GetEmployeeShiftsQuery($this->tenantId(), $this->employeeId(), $from, $to, $open);
    }

    private function tenantId(): string
    {
        return (string) $this->tenantContext->getTenantId();
    }

    private function employeeId(): string
    {
        $user = auth()->user();
        $employeeId = $user instanceof User ? Employee::query()->where('user_id', $user->getKey())->value('id') : null;

        return is_string($employeeId) ? $employeeId : throw new ShiftAccessDeniedException('No employee record is linked to your account.');
    }
}

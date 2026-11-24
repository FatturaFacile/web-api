<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Modules\AgentCommissions\Application\Queries\GetEnasarcoOverviewHandler;
use Modules\AgentCommissions\Application\Queries\GetEnasarcoOverviewQuery;
use Modules\AgentCommissions\Http\Controllers\Concerns\ResolvesAgentCommissionContext;
use Modules\AgentCommissions\Http\Requests\EnasarcoOverviewRequest;
use OpenApi\Attributes as OA;

/**
 * Enasarco and FIRR position per agent and calendar year: contribution base against the ceiling (massimale),
 * contributions against the floor (minimale), FIRR accrued.
 */
final class EnasarcoController extends Controller
{
    use ApiResponse;
    use ResolvesAgentCommissionContext;

    public function __construct(private readonly GetEnasarcoOverviewHandler $overview) {}

    #[OA\Get(path: '/api/v1/agent-commissions/enasarco/overview', operationId: 'agentCommissionsEnasarcoOverview', summary: 'Yearly Enasarco (massimale, minimale) and FIRR position of the agents', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Agent Commissions'], responses: [new OA\Response(response: 200, description: 'Enasarco overview')])]
    public function overview(EnasarcoOverviewRequest $request): JsonResponse
    {
        $year = (int) ($request->validated('year') ?? CarbonImmutable::today()->year);

        return $this->successResponse($this->overview->handle(new GetEnasarcoOverviewQuery($this->tenantId(), $year, $request->validated('agent_profile_id'))), 'OK', 200, ['year' => $year]);
    }
}

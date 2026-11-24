<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\AgentCommissions\Domain\Enums\AccrualBasis;
use Modules\AgentCommissions\Domain\Enums\AgentType;
use Modules\AgentCommissions\Domain\Enums\EnasarcoMandate;
use Modules\AgentCommissions\Domain\Enums\EntryStatus;
use Modules\AgentCommissions\Domain\Enums\FiscalRegime;
use Modules\AgentCommissions\Domain\Enums\PeriodType;
use Modules\AgentCommissions\Domain\Enums\RateType;
use Modules\AgentCommissions\Domain\Enums\SettlementChannel;
use Modules\AgentCommissions\Domain\Enums\SplitMode;
use Modules\AgentCommissions\Domain\Enums\StatementStatus;
use Modules\AgentCommissions\Models\AgentProfile;
use OpenApi\Attributes as OA;

/**
 * The enumerations of the module as value lists. The labels are not served here: the frontend resolves
 * agentCommissions.<group>.<value> from the translation dictionary seeded by the API.
 */
final class LookupController extends Controller
{
    use ApiResponse;

    #[OA\Get(path: '/api/v1/agent-commissions/lookups', operationId: 'agentCommissionsLookups', summary: 'Enumerations of the agent commission module', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Agent Commissions'], responses: [new OA\Response(response: 200, description: 'Lookups')])]
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', AgentProfile::class);

        return $this->successResponse([
            'agent_types' => AgentType::values(),
            'fiscal_regimes' => FiscalRegime::values(),
            'enasarco_mandates' => EnasarcoMandate::values(),
            'accrual_bases' => AccrualBasis::values(),
            'rate_types' => RateType::values(),
            'split_modes' => SplitMode::values(),
            'settlement_channels' => SettlementChannel::values(),
            'period_types' => PeriodType::values(),
            'statement_statuses' => StatementStatus::values(),
            'entry_statuses' => EntryStatus::values(),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Modules\DebtCollection\Application\Commands\GenerateNoticesCommand;
use Modules\DebtCollection\Application\Commands\GenerateNoticesHandler;
use Modules\DebtCollection\Application\Queries\GetDashboardHandler;
use Modules\DebtCollection\Application\Queries\GetDashboardQuery;
use Modules\DebtCollection\Application\Queries\ListProposalsHandler;
use Modules\DebtCollection\Application\Queries\ListProposalsQuery;
use Modules\DebtCollection\Http\Controllers\Concerns\ResolvesDebtCollectionContext;
use Modules\DebtCollection\Http\Requests\CalculateInterestRequest;
use Modules\DebtCollection\Http\Requests\GenerateNoticesRequest;
use Modules\DebtCollection\Http\Requests\ProposalIndexRequest;
use Modules\DebtCollection\Http\Resources\BatchResource;
use Modules\DebtCollection\Http\Resources\NoticeResource;
use Modules\DebtCollection\Models\Notice;
use Modules\DebtCollection\Services\CalculationService;
use Modules\DebtCollection\Services\DispatchService;
use Modules\DebtCollection\Services\ExclusionService;
use Modules\DebtCollection\Services\InterestCalculator;
use Modules\DebtCollection\Services\ReceiptService;
use OpenApi\Attributes as OA;

/**
 * The dunning dashboard, the automatic proposal (overdue items due for the next level, with exclusions flagged), the
 * interest breakdown and the generation of notices from the proposal.
 */
final class ProposalController extends Controller
{
    use ApiResponse;
    use ResolvesDebtCollectionContext;

    public function __construct(
        private readonly ListProposalsHandler $proposals,
        private readonly GetDashboardHandler $dashboard,
        private readonly GenerateNoticesHandler $generate,
        private readonly CalculationService $calculation,
    ) {}

    #[OA\Get(path: '/api/v1/debt-collection/lookups', operationId: 'debtCollectionLookups', summary: 'Option lists of the dunning module', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], responses: [new OA\Response(response: 200, description: 'Lookups')])]
    public function lookups(): JsonResponse
    {
        Gate::authorize('viewAny', Notice::class);

        return $this->successResponse([
            'levels' => [0, 1, 2, 3],
            'channels' => DispatchService::Channels,
            'jurisdictions' => ['CH', 'IT'],
            'exclusion_types' => ExclusionService::Types,
            'notice_kinds' => ['reminder', 'messa_in_mora'],
            'notice_statuses' => ['draft', 'queued', 'sent', 'delivered', 'failed', 'cancelled'],
            'receipt_kinds' => ReceiptService::Kinds,
            'interest_bases' => InterestCalculator::Bases,
            'interest_kinds' => ['statutory_ch', 'ecb_it', 'contractual'],
            'currencies' => ['CHF', 'EUR'],
            'plan_statuses' => ExclusionService::PlanStatuses,
            'template_codes' => ['level_0', 'level_1', 'level_2', 'level_3', 'messa_in_mora'],
        ]);
    }

    #[OA\Get(path: '/api/v1/debt-collection/dashboard', operationId: 'debtCollectionDashboard', summary: 'Dunning dashboard figures', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], responses: [new OA\Response(response: 200, description: 'Dashboard')])]
    public function dashboard(): JsonResponse
    {
        Gate::authorize('viewAny', Notice::class);

        return $this->successResponse($this->dashboard->handle(new GetDashboardQuery($this->tenantId(), $this->asOf(null))));
    }

    #[OA\Get(path: '/api/v1/debt-collection/proposals', operationId: 'debtCollectionProposals', summary: 'Automatic dunning proposal with exclusions, interest and fees', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], responses: [new OA\Response(response: 200, description: 'Proposal items and groups')])]
    public function index(ProposalIndexRequest $request): JsonResponse
    {
        $filters = $request->safe()->except('as_of');
        $filters['include_excluded'] = (bool) ($filters['include_excluded'] ?? false);

        return $this->successResponse($this->proposals->handle(new ListProposalsQuery($this->tenantId(), $filters, $this->asOf($request->validated('as_of')))));
    }

    #[OA\Post(path: '/api/v1/debt-collection/calculate', operationId: 'debtCollectionCalculate', summary: 'Interest and fee breakdown of an open item or an amount', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], responses: [new OA\Response(response: 200, description: 'Calculation')])]
    public function calculate(CalculateInterestRequest $request): JsonResponse
    {
        $input = $request->validated();

        return $this->successResponse($this->calculation->calculate($this->tenantId(), $input, Carbon::parse($this->asOf($input['as_of'] ?? null))));
    }

    #[OA\Post(path: '/api/v1/debt-collection/proposals/generate', operationId: 'debtCollectionGenerateNotices', summary: 'Generate the notices of selected proposal groups, optionally posting charges and dispatching', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], responses: [new OA\Response(response: 201, description: 'Notices and batch')])]
    public function store(GenerateNoticesRequest $request): JsonResponse
    {
        $data = $request->validated();
        $result = $this->generate->handle(new GenerateNoticesCommand(
            $this->tenantId(),
            $data['groups'],
            $this->asOf($data['as_of'] ?? null),
            $data['channel'],
            $data['locale'] ?? null,
            (bool) ($data['post_charges'] ?? false),
            (bool) ($data['dispatch'] ?? false),
            $this->userId($request),
        ));

        return $this->successResponse([
            'notices' => NoticeResource::collection(collect($result['notices'])->each->loadMissing('customer')),
            'batch' => $result['batch'] === null ? null : new BatchResource($result['batch']),
        ], 'Notices generated', 201);
    }
}

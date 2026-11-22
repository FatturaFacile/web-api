<?php

declare(strict_types=1);

namespace Modules\Rebates\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Modules\Rebates\Http\Controllers\Concerns\InteractsWithRebates;
use Modules\Rebates\Http\Requests\AccrualRunRequest;
use Modules\Rebates\Http\Requests\ContributionRequest;
use Modules\Rebates\Http\Requests\ReverseRunRequest;
use Modules\Rebates\Http\Resources\AccrualRunResource;
use Modules\Rebates\Http\Resources\ContributionResource;
use Modules\Rebates\Models\RebateAccrualRun;
use Modules\Rebates\Models\RebateAgreement;
use Modules\Rebates\Models\RebateContribution;
use Modules\Rebates\Services\RebateContributionService;
use Modules\Rebates\Services\SupplierAccrualRunService;
use Modules\Rebates\Services\SupplierRebateProgressService;
use OpenApi\Attributes as OA;

/**
 * Supplier rebate programmes: live tier progress, marketing contributions and the periodic accrual runs.
 */
final class SupplierProgramController extends Controller
{
    use InteractsWithRebates;

    public function __construct(
        private readonly SupplierRebateProgressService $progress,
        private readonly RebateContributionService $contributions,
        private readonly SupplierAccrualRunService $runs,
    ) {}

    #[OA\Get(path: '/api/v1/rebates/agreements/{id}/progress', operationId: 'rebateAgreementProgress', description: 'Qualifying spend, current tier, accrued rebate, exact distance to the next tier or bonus target and the rebate it would earn. Query: as_of (date).', summary: 'Live progress of a rebate agreement', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Rebates'], responses: [
        new OA\Response(response: 200, description: 'Progress of the agreement', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(response: 404, description: 'Agreement not found'),
    ])]
    public function progress(Request $request, string $id): JsonResponse
    {
        $agreement = RebateAgreement::query()->with('tiers')->findOrFail($id);
        $this->authorize('view', $agreement);
        $filters = $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json(['data' => $this->progress->progress($agreement, Carbon::parse((string) ($filters['as_of'] ?? now()->toDateString())))]);
    }

    #[OA\Get(path: '/api/v1/rebates/agreements/{id}/contributions', operationId: 'listRebateContributions', description: 'Marketing, co-marketing and listing contributions of the agreement.', summary: 'List contributions', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Rebates'], responses: [
        new OA\Response(response: 200, description: 'Contributions', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
    ])]
    public function contributions(string $id): AnonymousResourceCollection
    {
        $agreement = RebateAgreement::query()->findOrFail($id);
        $this->authorize('view', $agreement);

        return ContributionResource::collection($agreement->contributions()->get());
    }

    #[OA\Post(path: '/api/v1/rebates/agreements/{id}/contributions', operationId: 'createRebateContribution', description: 'Plans a contribution of the supplier on a due date.', summary: 'Add a contribution', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Rebates'], responses: [
        new OA\Response(response: 201, description: 'Contribution created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(response: 422, description: 'Validation failed'),
    ])]
    public function storeContribution(ContributionRequest $request, string $id): JsonResponse
    {
        $agreement = RebateAgreement::query()->findOrFail($id);
        $this->authorize('update', $agreement);

        return (new ContributionResource($this->contributions->add($agreement, $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Put(path: '/api/v1/rebates/contributions/{id}', operationId: 'updateRebateContribution', description: 'Changes a planned contribution.', summary: 'Update a contribution', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Rebates'], responses: [
        new OA\Response(response: 200, description: 'Contribution updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(response: 422, description: 'Validation failed'),
    ])]
    public function updateContribution(ContributionRequest $request, string $id): ContributionResource
    {
        $contribution = RebateContribution::query()->findOrFail($id);
        $this->authorize('update', $contribution);

        return new ContributionResource($this->contributions->update($contribution, $request->validated()));
    }

    #[OA\Delete(path: '/api/v1/rebates/contributions/{id}', operationId: 'deleteRebateContribution', description: 'Removes a planned contribution.', summary: 'Delete a contribution', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Rebates'], responses: [
        new OA\Response(response: 204, description: 'Contribution deleted'),
        new OA\Response(response: 422, description: 'The contribution is already accrued'),
    ])]
    public function destroyContribution(string $id): Response
    {
        $contribution = RebateContribution::query()->findOrFail($id);
        $this->authorize('delete', $contribution);
        $this->contributions->remove($contribution);

        return response()->noContent();
    }

    #[OA\Get(path: '/api/v1/rebates/agreements/{id}/accrual-runs', operationId: 'listRebateAccrualRuns', description: 'Periodic accrual runs of the agreement, latest first, with the invoices that contributed to each.', summary: 'List accrual runs', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Rebates'], responses: [
        new OA\Response(response: 200, description: 'Accrual runs', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
    ])]
    public function runs(string $id): AnonymousResourceCollection
    {
        $agreement = RebateAgreement::query()->findOrFail($id);
        $this->authorize('view', $agreement);

        return AccrualRunResource::collection($agreement->accrualRuns()->with('lines')->get());
    }

    #[OA\Post(path: '/api/v1/rebates/agreements/{id}/accrual-runs', operationId: 'createRebateAccrualRun', description: 'Runs the periodic accrual of the agreement up to period_end (today by default). Idempotent: a period already covered by a posted run returns that run. Books the accrual in the ledger when the agreement has its accrual and income accounts.', summary: 'Run the periodic accrual', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Rebates'], responses: [
        new OA\Response(response: 201, description: 'Accrual run', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(response: 422, description: 'The period is locked or the agreement is not an active supplier agreement'),
    ])]
    public function createRun(AccrualRunRequest $request, string $id): JsonResponse
    {
        $agreement = RebateAgreement::query()->with('tiers')->findOrFail($id);
        $this->authorize('update', $agreement);
        $end = Carbon::parse((string) ($request->validated('period_end') ?? now()->toDateString()))->startOfDay();
        $start = $request->validated('period_start') === null ? null : Carbon::parse((string) $request->validated('period_start'))->startOfDay();

        return (new AccrualRunResource($this->runs->run($agreement, $end, $start, $this->userId())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Post(path: '/api/v1/rebates/accrual-runs/{id}/reverse', operationId: 'reverseRebateAccrualRun', description: 'Reverses the latest posted run of the agreement: cancels its accruals and reverses its ledger entry. Not allowed once the accruals are part of a settlement proposal.', summary: 'Reverse an accrual run', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Rebates'], responses: [
        new OA\Response(response: 200, description: 'Reversed run', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(response: 422, description: 'The run cannot be reversed'),
    ])]
    public function reverseRun(ReverseRunRequest $request, string $id): AccrualRunResource
    {
        $run = RebateAccrualRun::query()->findOrFail($id);
        $this->authorize('update', $run);

        return new AccrualRunResource($this->runs->reverse($run, (string) $request->validated('reason'), $this->userId()));
    }
}

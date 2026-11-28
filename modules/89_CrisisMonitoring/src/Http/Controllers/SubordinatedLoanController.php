<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\CrisisMonitoring\Application\Commands\UpsertSubordinatedLoanRangruecktrittCommand;
use Modules\CrisisMonitoring\Application\Handlers\UpsertSubordinatedLoanRangruecktrittHandler;
use Modules\CrisisMonitoring\Application\Services\CrisisTexts;
use Modules\CrisisMonitoring\Application\Services\RangruecktrittAgreementBuilder;
use Modules\CrisisMonitoring\Domain\Swiss\RangruecktrittCalculator;
use Modules\CrisisMonitoring\Http\Controllers\Concerns\ResolvesCrisisContext;
use Modules\CrisisMonitoring\Http\Requests\CrisisQueryRequest;
use Modules\CrisisMonitoring\Http\Requests\SubordinatedLoanRequest;
use Modules\CrisisMonitoring\Http\Resources\SubordinatedLoanResource;
use Modules\CrisisMonitoring\Models\SubordinatedLoanRangruecktritt;
use OpenApi\Attributes as OA;

/**
 * The registry of the subordinated loans (Rangrücktritt, Art. 725b CO) and the printable agreement.
 */
final class SubordinatedLoanController extends Controller
{
    use ApiResponse;
    use ResolvesCrisisContext;

    #[OA\Get(path: '/api/v1/crisis-monitoring/subordinated-loans', operationId: 'listCrisisMonitoringSubordinatedLoans', summary: 'Subordinated loans (Rangrücktritt)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Crisis Monitoring'], responses: [new OA\Response(response: 200, description: 'The loans, newest agreement first')])]
    public function index(): JsonResponse
    {
        $loans = SubordinatedLoanRangruecktritt::query()->orderByDesc('agreement_date')->get();

        return $this->successResponse(SubordinatedLoanResource::collection($loans)->resolve(), CrisisTexts::get('crisisApi.message.loans', CrisisTexts::locale()));
    }

    #[OA\Post(path: '/api/v1/crisis-monitoring/subordinated-loans', operationId: 'createCrisisMonitoringSubordinatedLoan', summary: 'Register a subordinated loan', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Crisis Monitoring'], responses: [new OA\Response(response: 201, description: 'The loan')])]
    public function store(SubordinatedLoanRequest $request, UpsertSubordinatedLoanRangruecktrittHandler $handler): JsonResponse
    {
        $loan = $handler->handle(new UpsertSubordinatedLoanRangruecktrittCommand(null, $request->validated()));

        return $this->createdResponse(new SubordinatedLoanResource($loan), CrisisTexts::get('crisisApi.message.loan_saved', CrisisTexts::locale()));
    }

    #[OA\Put(path: '/api/v1/crisis-monitoring/subordinated-loans/{id}', operationId: 'updateCrisisMonitoringSubordinatedLoan', summary: 'Update a subordinated loan', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Crisis Monitoring'], responses: [new OA\Response(response: 200, description: 'The loan')])]
    public function update(SubordinatedLoanRequest $request, string $id, UpsertSubordinatedLoanRangruecktrittHandler $handler): JsonResponse
    {
        $loan = $handler->handle(new UpsertSubordinatedLoanRangruecktrittCommand($id, $request->validated()));

        return $this->successResponse(new SubordinatedLoanResource($loan), CrisisTexts::get('crisisApi.message.loan_saved', CrisisTexts::locale()));
    }

    #[OA\Delete(path: '/api/v1/crisis-monitoring/subordinated-loans/{id}', operationId: 'deleteCrisisMonitoringSubordinatedLoan', summary: 'Delete a subordinated loan', description: 'Soft delete: the agreement stays in the audit trail.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Crisis Monitoring'], responses: [new OA\Response(response: 200, description: 'Deleted')])]
    public function destroy(string $id): JsonResponse
    {
        SubordinatedLoanRangruecktritt::query()->findOrFail($id)->delete();

        return $this->successResponse(null, CrisisTexts::get('crisisApi.message.loan_deleted', CrisisTexts::locale()));
    }

    #[OA\Get(path: '/api/v1/crisis-monitoring/subordinated-loans/{id}/agreement/pdf', operationId: 'downloadCrisisMonitoringAgreementPdf', summary: 'Rangrücktrittsvereinbarung summary (PDF, German and the chosen language)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Crisis Monitoring'], responses: [new OA\Response(response: 200, description: 'The PDF document')])]
    public function agreementPdf(CrisisQueryRequest $request, string $id, RangruecktrittAgreementBuilder $builder, RangruecktrittCalculator $calculator): Response
    {
        $loan = SubordinatedLoanRangruecktritt::query()->findOrFail($id);
        $tenant = $this->tenant();
        $claim = $calculator->claim([
            'principal' => (string) $loan->principal,
            'interest_rate' => (string) $loan->interest_rate,
            'interest_treatment' => (string) $loan->interest_treatment,
            'scope' => (string) $loan->scope,
            'effective_from' => CarbonImmutable::parse($loan->effective_from),
            'deferral_end_date' => $loan->deferral_end_date === null ? null : CarbonImmutable::parse($loan->deferral_end_date),
        ], CarbonImmutable::today(), 12);
        $content = $builder->pdf((string) ($tenant->legal_name ?: $tenant->name), $loan, $claim, CrisisTexts::locale($request->validated('locale')), CarbonImmutable::now());

        return $this->pdfDownload($content, 'rangruecktritt-'.$loan->id.'.pdf');
    }
}

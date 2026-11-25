<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\TenderManagement\Application\Commands\CalculateEconomicOfferAndReducedBidBondCommand;
use Modules\TenderManagement\Application\Commands\CalculateEconomicOfferAndReducedBidBondHandler;
use Modules\TenderManagement\Application\Commands\ConvertAwardedTenderToContractAndJobCommand;
use Modules\TenderManagement\Application\Commands\ConvertAwardedTenderToContractAndJobHandler;
use Modules\TenderManagement\Application\Queries\EvaluateTenderEligibilityAndGoNoGoHandler;
use Modules\TenderManagement\Application\Queries\EvaluateTenderEligibilityAndGoNoGoQuery;
use Modules\TenderManagement\Application\Queries\ExportEdgueHandler;
use Modules\TenderManagement\Application\Queries\ExportEdgueQuery;
use Modules\TenderManagement\Application\Queries\GetSubmissionReadinessHandler;
use Modules\TenderManagement\Application\Queries\GetSubmissionReadinessQuery;
use Modules\TenderManagement\Domain\Enums\ParticipationStatus;
use Modules\TenderManagement\Exceptions\TenderException;
use Modules\TenderManagement\Http\Controllers\Concerns\InteractsWithTenders;
use Modules\TenderManagement\Http\Requests\ConvertAwardRequest;
use Modules\TenderManagement\Http\Requests\EconomicOfferRequest;
use Modules\TenderManagement\Http\Requests\ListFiltersRequest;
use Modules\TenderManagement\Http\Requests\SaveParticipationRequest;
use Modules\TenderManagement\Http\Requests\TransitionRequest;
use Modules\TenderManagement\Http\Resources\ParticipationResource;
use Modules\TenderManagement\Models\TenderNotice;
use Modules\TenderManagement\Models\TenderParticipation;
use Modules\TenderManagement\Services\ParticipationService;
use OpenApi\Attributes as OA;

/**
 * Participation dossiers: the pipeline, the Go/No-Go evaluation, the economic offer, the submission and the award conversion.
 */
final class ParticipationController extends Controller
{
    use ApiResponse;
    use InteractsWithTenders;

    #[OA\Get(path: '/api/v1/tender-management/participations', operationId: 'tenderManagementListParticipations', summary: 'List participation dossiers (pipeline)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Tender Management'], responses: [new OA\Response(response: 200, description: 'Dossiers, paginated')])]
    public function index(ListFiltersRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', TenderParticipation::class);

        $page = TenderParticipation::query()
            ->with('notice')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->input('status')))
            ->when($request->filled('jurisdiction'), fn ($query) => $query->whereHas('notice', fn ($notice) => $notice->where('jurisdiction', $request->input('jurisdiction'))))
            ->when($request->filled('deadline_within_days'), fn ($query) => $query->whereHas('notice', fn ($notice) => $notice->whereBetween('submission_peremptory_deadline_at', [now(), now()->addDays((int) $request->input('deadline_within_days'))])))
            ->when($request->filled('q'), function ($query) use ($request): void {
                $term = '%'.mb_strtolower((string) $request->input('q')).'%';
                $query->whereHas('notice', fn ($notice) => $notice->where(fn ($inner) => $inner->whereRaw('LOWER(title) LIKE ?', [$term])->orWhereRaw('LOWER(contracting_authority_name) LIKE ?', [$term])->orWhereRaw('LOWER(COALESCE(cig_code_it, \'\')) LIKE ?', [$term])));
            })
            ->orderByDesc('updated_at')
            ->paginate($this->perPage());

        return $this->paged($page, ParticipationResource::class, $this->message('listed'));
    }

    #[OA\Post(path: '/api/v1/tender-management/participations', operationId: 'tenderManagementCreateParticipation', summary: 'Open a participation dossier for a tender', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Tender Management'], responses: [new OA\Response(response: 201, description: 'Dossier created')])]
    public function store(SaveParticipationRequest $request, ParticipationService $participations): JsonResponse
    {
        Gate::authorize('create', TenderParticipation::class);

        $data = $request->validated();
        $notice = TenderNotice::query()->findOrFail($data['tender_id']);
        $participation = $participations->create($notice, $data, $this->userId(), CarbonImmutable::now());

        return $this->successResponse(new ParticipationResource($participation->load('notice')), $this->message('saved'), 201);
    }

    #[OA\Get(path: '/api/v1/tender-management/participations/{id}', operationId: 'tenderManagementShowParticipation', summary: 'Participation dossier with its tender', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Tender Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Dossier detail')])]
    public function show(string $id): JsonResponse
    {
        $participation = $this->findParticipation($id, 'view');
        request()->attributes->set('tender_detail', true);

        return $this->successResponse(new ParticipationResource($participation));
    }

    #[OA\Put(path: '/api/v1/tender-management/participations/{id}', operationId: 'tenderManagementUpdateParticipation', summary: 'Update a participation dossier', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Tender Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Dossier updated')])]
    public function update(SaveParticipationRequest $request, string $id, ParticipationService $participations): JsonResponse
    {
        $participation = $this->findParticipation($id, 'update');
        $updated = $participations->update($participation, $request->validated(), $this->userId());
        request()->attributes->set('tender_detail', true);

        return $this->successResponse(new ParticipationResource($updated->load('notice')), $this->message('saved'));
    }

    #[OA\Delete(path: '/api/v1/tender-management/participations/{id}', operationId: 'tenderManagementDeleteParticipation', summary: 'Delete a dossier that was not submitted', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Tender Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Dossier deleted')])]
    public function destroy(string $id): JsonResponse
    {
        $participation = $this->findParticipation($id, 'delete');

        if (! in_array($participation->status, [ParticipationStatus::Scouting, ParticipationStatus::GoApproved, ParticipationStatus::PreparingEnvelopes], true)) {
            throw new TenderException('invalid_transition', 422, ['from' => $participation->status->value, 'to' => 'deleted']);
        }

        $participation->delete();

        return $this->successResponse(null, $this->message('deleted'));
    }

    #[OA\Post(path: '/api/v1/tender-management/participations/{id}/transition', operationId: 'tenderManagementTransitionParticipation', summary: 'Move the dossier to another pipeline status', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Tender Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Dossier moved')])]
    public function transition(TransitionRequest $request, string $id, ParticipationService $participations): JsonResponse
    {
        $participation = $this->findParticipation($id, 'update');
        $moved = $participations->transition($participation, ParticipationStatus::from((string) $request->validated('status')), CarbonImmutable::now());
        request()->attributes->set('tender_detail', true);

        return $this->successResponse(new ParticipationResource($moved->load('notice')), $this->message('transitioned'));
    }

    #[OA\Post(path: '/api/v1/tender-management/participations/{id}/evaluate-eligibility', operationId: 'tenderManagementEvaluateEligibility', summary: 'Go/No-Go evaluation against the ERP and the qualification vault', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Tender Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Go/No-Go matrix')])]
    public function evaluate(string $id, EvaluateTenderEligibilityAndGoNoGoHandler $handler): JsonResponse
    {
        $this->findParticipation($id, 'update');

        return $this->successResponse($handler->handle(new EvaluateTenderEligibilityAndGoNoGoQuery($id)), $this->message('evaluated'));
    }

    #[OA\Post(path: '/api/v1/tender-management/participations/{id}/economic-offer', operationId: 'tenderManagementEconomicOffer', summary: 'Simulate the economic offer, the OEPV score and the reduced bid bond', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Tender Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Offer simulation')])]
    public function economicOffer(EconomicOfferRequest $request, string $id, CalculateEconomicOfferAndReducedBidBondHandler $handler): JsonResponse
    {
        $persist = $request->boolean('persist');
        $this->findParticipation($id, $persist ? 'update' : 'view');

        $result = $handler->handle(new CalculateEconomicOfferAndReducedBidBondCommand(
            participationId: $id,
            discountPercentage: (string) $request->input('discount_percentage'),
            estimatedTotalCost: $request->filled('estimated_total_cost') ? (string) $request->input('estimated_total_cost') : null,
            otherDirectCosts: (string) $request->input('other_direct_costs', '0'),
            companySafetyCosts: $request->filled('company_safety_costs_declared') ? (string) $request->input('company_safety_costs_declared') : null,
            companyLaborCosts: $request->filled('company_labor_costs_declared') ? (string) $request->input('company_labor_costs_declared') : null,
            bidBasePercentage: (string) $request->input('bid_base_percentage'),
            technicalScoreEstimate: $request->filled('technical_score_estimate') ? (string) $request->input('technical_score_estimate') : null,
            bestCompetitorDiscount: $request->filled('best_competitor_discount') ? (string) $request->input('best_competitor_discount') : null,
            competitorDiscounts: array_map('strval', (array) $request->input('competitor_discounts', [])),
            persist: $persist,
        ));

        return $this->successResponse($result, $this->message('simulated'));
    }

    #[OA\Get(path: '/api/v1/tender-management/participations/{id}/readiness', operationId: 'tenderManagementReadiness', summary: 'Submission readiness of the three envelopes', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Tender Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Readiness')])]
    public function readiness(string $id, GetSubmissionReadinessHandler $handler): JsonResponse
    {
        $this->findParticipation($id, 'view');

        return $this->successResponse($handler->handle(new GetSubmissionReadinessQuery($id)));
    }

    #[OA\Post(path: '/api/v1/tender-management/participations/{id}/submit', operationId: 'tenderManagementSubmit', summary: 'Lodge the dossier once the submission gate is clear', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Tender Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Dossier submitted')])]
    public function submit(string $id, ParticipationService $participations): JsonResponse
    {
        $participation = $this->findParticipation($id, 'update');
        request()->attributes->set('tender_detail', true);

        return $this->successResponse(new ParticipationResource($participations->submit($participation, CarbonImmutable::now())->load('notice')), $this->message('submitted'));
    }

    #[OA\Post(path: '/api/v1/tender-management/participations/{id}/convert', operationId: 'tenderManagementConvertAward', summary: 'Convert an awarded tender into a contract and a project', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Tender Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 201, description: 'Contract and project created')])]
    public function convert(ConvertAwardRequest $request, string $id, ConvertAwardedTenderToContractAndJobHandler $handler): JsonResponse
    {
        $this->findParticipation($id, 'convert');

        $result = $handler->handle(new ConvertAwardedTenderToContractAndJobCommand(
            participationId: $id,
            userId: $this->userId(),
            contactId: $request->input('contact_id'),
            projectManagerUserId: $request->input('project_manager_user_id'),
            plannedStartDate: $request->input('planned_start_date'),
            plannedEndDate: $request->input('planned_end_date'),
            contractTitle: $request->input('contract_title'),
        ));
        request()->attributes->set('tender_detail', true);

        return $this->successResponse([
            'participation' => new ParticipationResource($result['participation']->load('notice')),
            'contract' => ['id' => $result['contract']->id, 'contract_number' => $result['contract']->contract_number, 'title' => $result['contract']->title],
            'project' => ['id' => $result['project']->id, 'project_number' => $result['project']->project_number, 'name' => $result['project']->name, 'cig_code' => $result['project']->cig_code, 'cup_code' => $result['project']->cup_code],
            'references' => $result['references'],
        ], $this->message('converted'), 201);
    }

    #[OA\Get(path: '/api/v1/tender-management/participations/{id}/edgue', operationId: 'tenderManagementEdgue', summary: 'Generate the eDGUE data and XML', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Tender Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'eDGUE document')])]
    public function edgue(string $id, ExportEdgueHandler $handler): JsonResponse
    {
        $this->findParticipation($id, 'update');

        return $this->successResponse($handler->handle(new ExportEdgueQuery($id)), $this->message('edgue_generated'));
    }

    #[OA\Get(path: '/api/v1/tender-management/participations/{id}/edgue/download', operationId: 'tenderManagementEdgueDownload', summary: 'Download the eDGUE as XML or PDF', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Tender Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'eDGUE file')])]
    public function edgueDownload(ListFiltersRequest $request, string $id, ExportEdgueHandler $handler): Response
    {
        $this->findParticipation($id, 'update');
        $format = $request->query('format') === 'pdf' ? 'pdf' : 'xml';
        $content = (string) $handler->handle(new ExportEdgueQuery($id, $format));

        return response($content, 200, [
            'Content-Type' => $format === 'pdf' ? 'application/pdf' : 'application/xml',
            'Content-Disposition' => 'attachment; filename="edgue-'.$id.'.'.$format.'"',
        ]);
    }
}

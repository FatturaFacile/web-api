<?php

declare(strict_types=1);

namespace Modules\Customs\Http\Controllers;

use App\Contracts\PdfGenerator;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Modules\Customs\DTOs\RetrievedAssessment;
use Modules\Customs\Exceptions\CustomsException;
use Modules\Customs\Exceptions\CustomsGatewayException;
use Modules\Customs\Http\Requests\RetrieveAssessmentsRequest;
use Modules\Customs\Http\Requests\UploadAssessmentRequest;
use Modules\Customs\Http\Resources\CustomsRecordResource;
use Modules\Customs\Models\CustomsAssessmentDecision;
use Modules\Customs\Models\CustomsRetrievalRun;
use Modules\Customs\Services\AssessmentArchive;
use Modules\Customs\Services\AssessmentReportingService;
use Modules\Customs\Services\AssessmentRetrievalService;
use Modules\Customs\Services\Edec\EdecSoapTransport;
use OpenApi\Attributes as OA;

/**
 * The archive of the assessment decisions: search, files, integrity, control list and statistics.
 */
final class AssessmentDecisionController extends CustomsController
{
    public function __construct(
        TenantContext $tenantContext,
        private readonly AssessmentArchive $archive,
        private readonly AssessmentRetrievalService $retrieval,
        private readonly AssessmentReportingService $reporting,
        private readonly EdecSoapTransport $soap,
        private readonly PdfGenerator $pdfGenerator,
    ) {
        parent::__construct($tenantContext);
    }

    /**
     * Search assessment decisions.
     */
    #[OA\Get(path: '/api/v1/customs/assessment-decisions', operationId: 'listCustomsAssessmentDecisions', description: 'Search the archive by date, document type, direction and MRN.', summary: 'Search assessment decisions', security: [['bearerAuth' => []]], tags: ['Customs'], parameters: [
        new OA\Parameter(name: 'mrn', description: 'Start of the MRN', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'document_type', description: 'Document type of the edecReceipt schema', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'direction', description: 'import or export', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'date_from', description: 'First document date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'date_to', description: 'Last document date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
    ], responses: [
        new OA\Response(response: 200, description: 'Decisions', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CustomsAssessmentDecision::class);

        $filters = $request->validate([
            'mrn' => ['sometimes', 'string', 'max:30'],
            'document_type' => ['sometimes', 'string', 'max:40'],
            'direction' => ['sometimes', Rule::in(['import', 'export'])],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date'],
        ]);

        $query = CustomsAssessmentDecision::query()
            ->with('ledgerPosting')
            ->when($filters['mrn'] ?? null, static fn ($query, $mrn) => $query->where('mrn', 'like', $mrn.'%'))
            ->when($filters['document_type'] ?? null, static fn ($query, $type) => $query->where('document_type', $type))
            ->when($filters['direction'] ?? null, static fn ($query, $direction) => $query->where('direction', $direction))
            ->when($filters['date_from'] ?? null, static fn ($query, $date) => $query->whereDate('document_date', '>=', $date))
            ->when($filters['date_to'] ?? null, static fn ($query, $date) => $query->whereDate('document_date', '<=', $date))
            ->orderByDesc('document_date')
            ->orderBy('mrn');

        return CustomsRecordResource::collection($query->gridPaginate($this->perPage($request)));
    }

    /**
     * Archive a decision file.
     */
    #[OA\Post(path: '/api/v1/customs/assessment-decisions/upload', operationId: 'uploadCustomsAssessmentDecision', description: 'Archive a decision downloaded by hand: the edecReceipt XML or the signed SOAP message, and optionally its PDF. A signed message has its signature checked.', summary: 'Archive assessment decision file', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(ref: '#/components/schemas/CustomsAssessmentUploadRequest'))), tags: ['Customs'], responses: [
        new OA\Response(response: 201, description: 'Decision archived', content: new OA\JsonContent(ref: '#/components/schemas/CustomsAssessmentDecisionResponse')),
        new OA\Response(response: 200, description: 'The decision was already in the archive', content: new OA\JsonContent(ref: '#/components/schemas/CustomsAssessmentDecisionResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsUnprocessable', response: 422),
    ])]
    public function upload(UploadAssessmentRequest $request): JsonResponse
    {
        $this->authorize('create', CustomsAssessmentDecision::class);

        $content = (string) $request->file('xml')?->get();
        $pdf = $request->file('pdf')?->get();
        $isEnvelope = preg_match('/<(?:[A-Za-z0-9_-]+:)?Envelope[\s>]/', $content) === 1;

        try {
            $assessment = new RetrievedAssessment(
                xml: $isEnvelope ? $this->soap->bodyOf($content) : $content,
                signedEnvelope: $isEnvelope ? $content : null,
                pdf: $pdf === false ? null : $pdf,
            );
        } catch (CustomsGatewayException $e) {
            throw new CustomsException($e->getMessage());
        }

        $result = $this->archive->store($this->tenant(), $assessment, 'upload');

        return (new CustomsRecordResource($result['decision']))->response()->setStatusCode($result['is_new'] ? 201 : 200);
    }

    /**
     * Retrieve decisions now.
     */
    #[OA\Post(path: '/api/v1/customs/assessment-decisions/retrieve', operationId: 'retrieveCustomsAssessmentDecisions', description: 'Run the download of the nightly job for a period, through the customs channel of the company.', summary: 'Retrieve assessment decisions', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CustomsRetrieveRequest')), tags: ['Customs'], responses: [
        new OA\Response(response: 200, description: 'Retrieval run', content: new OA\JsonContent(ref: '#/components/schemas/CustomsRecordResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsUnprocessable', response: 422),
    ])]
    public function retrieve(RetrieveAssessmentsRequest $request): CustomsRecordResource
    {
        $this->authorize('create', CustomsAssessmentDecision::class);

        $run = $this->retrieval->run($this->tenant(), CarbonImmutable::parse($request->validated('date_from')), CarbonImmutable::parse($request->validated('date_to')));
        $run->wasRecentlyCreated = false;

        return new CustomsRecordResource($run);
    }

    /**
     * Daily control list.
     */
    #[OA\Get(path: '/api/v1/customs/assessment-decisions/control-list', operationId: 'customsAssessmentControlList', description: 'Return the decisions received on a day, each with its error flags, and the retrieval runs of that day.', summary: 'Assessment control list', security: [['bearerAuth' => []]], tags: ['Customs'], parameters: [new OA\Parameter(name: 'date', description: 'The day, today when omitted', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'))], responses: [
        new OA\Response(response: 200, description: 'Control list', content: new OA\JsonContent(ref: '#/components/schemas/CustomsControlListResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
    ])]
    public function controlList(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CustomsAssessmentDecision::class);

        $date = $request->validate(['date' => ['sometimes', 'date']])['date'] ?? now()->toDateString();

        return response()->json(['data' => $this->reporting->controlList($date)]);
    }

    /**
     * Assessment statistics.
     */
    #[OA\Get(path: '/api/v1/customs/assessment-decisions/statistics', operationId: 'customsAssessmentStatistics', description: 'Return the number of decisions and the duty and VAT totals by month, direction and document type.', summary: 'Assessment statistics', security: [['bearerAuth' => []]], tags: ['Customs'], parameters: [
        new OA\Parameter(name: 'date_from', description: 'First document date', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'date_to', description: 'Last document date', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
    ], responses: [
        new OA\Response(response: 200, description: 'Statistics', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsUnprocessable', response: 422),
    ])]
    public function statistics(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CustomsAssessmentDecision::class);

        $period = $request->validate(['date_from' => ['required', 'date'], 'date_to' => ['required', 'date', 'after_or_equal:date_from']]);

        return response()->json(['data' => $this->reporting->statistics($period['date_from'], $period['date_to'])]);
    }

    /**
     * List the retrieval runs.
     */
    #[OA\Get(path: '/api/v1/customs/assessment-decisions/retrieval-runs', operationId: 'listCustomsRetrievalRuns', description: 'Return the downloads of assessment decisions, newest first, with their errors.', summary: 'List retrieval runs', security: [['bearerAuth' => []]], tags: ['Customs'], responses: [
        new OA\Response(response: 200, description: 'Runs', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
    ])]
    public function retrievalRuns(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CustomsRetrievalRun::class);

        return CustomsRecordResource::collection(CustomsRetrievalRun::query()->latest('started_at')->gridPaginate($this->perPage($request)));
    }

    /**
     * Show an assessment decision.
     */
    #[OA\Get(path: '/api/v1/customs/assessment-decisions/{id}', operationId: 'showCustomsAssessmentDecision', description: 'Return a decision with its signature check and its ledger state.', summary: 'Show assessment decision', security: [['bearerAuth' => []]], tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsRecordId')], responses: [
        new OA\Response(response: 200, description: 'Decision', content: new OA\JsonContent(ref: '#/components/schemas/CustomsAssessmentDecisionResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
    ])]
    public function show(string $id): CustomsRecordResource
    {
        $decision = $this->find($id);
        $this->authorize('view', $decision);

        return new CustomsRecordResource($decision->load('ledgerPosting'));
    }

    /**
     * Download the XML of a decision.
     */
    #[OA\Get(path: '/api/v1/customs/assessment-decisions/{id}/xml', operationId: 'customsAssessmentDecisionXml', description: 'Return the XML as archived. With signed=1, the signed message when there is one.', summary: 'Assessment decision XML', security: [['bearerAuth' => []]], tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsRecordId'), new OA\Parameter(name: 'signed', description: 'Return the signed message', in: 'query', required: false, schema: new OA\Schema(type: 'boolean'))], responses: [
        new OA\Response(response: 200, description: 'XML', content: new OA\MediaType(mediaType: 'application/xml', schema: new OA\Schema(type: 'string'))),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
    ])]
    public function xml(Request $request, string $id): Response
    {
        $decision = $this->find($id);
        $this->authorize('view', $decision);

        $path = $request->boolean('signed') ? $decision->envelope_path : $decision->xml_path;
        abort_if($path === null, 404);

        return $this->fileResponse((string) $this->archive->contents($path), "{$decision->mrn}_v{$decision->version}.xml", 'application/xml');
    }

    /**
     * Download the PDF of a decision.
     */
    #[OA\Get(path: '/api/v1/customs/assessment-decisions/{id}/pdf', operationId: 'customsAssessmentDecisionPdf', description: 'Return the PDF as received. When none was received, a readable copy made from the XML, which is marked as such.', summary: 'Assessment decision PDF', security: [['bearerAuth' => []]], tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsRecordId')], responses: [
        new OA\Response(response: 200, description: 'PDF', content: new OA\MediaType(mediaType: 'application/pdf', schema: new OA\Schema(type: 'string', format: 'binary'))),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
    ])]
    public function pdf(string $id): Response
    {
        $decision = $this->find($id);
        $this->authorize('view', $decision);

        $content = $decision->pdf_path !== null
            ? (string) $this->archive->contents($decision->pdf_path)
            : $this->pdfGenerator->render('customs::assessment', ['decision' => $decision, 'tenant' => $this->tenant()]);

        return $this->fileResponse($content, "{$decision->mrn}_v{$decision->version}.pdf", 'application/pdf');
    }

    /**
     * Check the integrity of a decision.
     */
    #[OA\Get(path: '/api/v1/customs/assessment-decisions/{id}/integrity', operationId: 'customsAssessmentDecisionIntegrity', description: 'Compare the archived files with the hashes taken at the download.', summary: 'Assessment decision integrity', security: [['bearerAuth' => []]], tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsRecordId')], responses: [
        new OA\Response(response: 200, description: 'Outcome', content: new OA\JsonContent(ref: '#/components/schemas/CustomsIntegrityResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
    ])]
    public function integrity(string $id): JsonResponse
    {
        $decision = $this->find($id);
        $this->authorize('view', $decision);

        return response()->json(['data' => $this->archive->verifyIntegrity($decision)]);
    }

    private function find(string $id): CustomsAssessmentDecision
    {
        return CustomsAssessmentDecision::query()->findOrFail($id);
    }
}

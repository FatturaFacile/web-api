<?php

declare(strict_types=1);

namespace Modules\Customs\Http\Controllers;

use App\Models\Contact;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Customs\Http\Requests\InvoiceDossierRequest;
use Modules\Customs\Http\Requests\MonthlySummaryRequest;
use Modules\Customs\Http\Requests\SaveChargeRequest;
use Modules\Customs\Http\Requests\SaveDeliveryRequest;
use Modules\Customs\Http\Requests\SaveDossierRequest;
use Modules\Customs\Http\Requests\StoreDossierDocumentRequest;
use Modules\Customs\Http\Resources\CustomsRecordResource;
use Modules\Customs\Models\CustomsDossier;
use Modules\Customs\Services\DossierService;
use OpenApi\Attributes as OA;

/**
 * Forwarding dossiers.
 */
final class DossierController extends CustomsController
{
    public function __construct(TenantContext $tenantContext, private readonly DossierService $dossiers)
    {
        parent::__construct($tenantContext);
    }

    /**
     * List dossiers.
     */
    #[OA\Get(path: '/api/v1/customs/dossiers', operationId: 'listCustomsDossiers', description: 'Return the dossiers of the company, newest first.', summary: 'List dossiers', security: [['bearerAuth' => []]], tags: ['Customs'], parameters: [
        new OA\Parameter(name: 'status', description: 'Only this status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'customer_contact_id', description: 'Only this customer', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ], responses: [
        new OA\Response(response: 200, description: 'Dossiers', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CustomsDossier::class);

        $query = CustomsDossier::query()
            ->when($request->filled('status'), static fn ($query) => $query->where('status', (string) $request->query('status')))
            ->when($request->filled('customer_contact_id'), static fn ($query) => $query->where('customer_contact_id', (string) $request->query('customer_contact_id')))
            ->latest();

        return CustomsRecordResource::collection($query->paginate($this->perPage($request)));
    }

    /**
     * Open a dossier.
     */
    #[OA\Post(path: '/api/v1/customs/dossiers', operationId: 'storeCustomsDossier', description: 'Open a forwarding dossier.', summary: 'Open dossier', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CustomsDossierRequest')), tags: ['Customs'], responses: [
        new OA\Response(response: 201, description: 'Dossier opened', content: new OA\JsonContent(ref: '#/components/schemas/CustomsDossierResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsUnprocessable', response: 422),
    ])]
    public function store(SaveDossierRequest $request): JsonResponse
    {
        $this->authorize('create', CustomsDossier::class);

        return (new CustomsRecordResource($this->dossiers->open($request->validated(), $this->actorId($request))))->response()->setStatusCode(201);
    }

    /**
     * Monthly summary of a customer.
     */
    #[OA\Get(path: '/api/v1/customs/dossiers/monthly-summary', operationId: 'customsDossierMonthlySummary', description: 'Return the dossiers of a customer opened in a month, with revenue, costs and result.', summary: 'Dossier monthly summary', security: [['bearerAuth' => []]], tags: ['Customs'], parameters: [
        new OA\Parameter(name: 'contact_id', description: 'The customer', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'year', description: 'The year', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'month', description: 'The month', in: 'query', required: true, schema: new OA\Schema(type: 'integer', maximum: 12, minimum: 1)),
    ], responses: [
        new OA\Response(response: 200, description: 'Summary', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsUnprocessable', response: 422),
    ])]
    public function monthlySummary(MonthlySummaryRequest $request): JsonResponse
    {
        $this->authorize('viewAny', CustomsDossier::class);

        $data = $request->validated();

        return response()->json(['data' => $this->dossiers->monthlySummary(Contact::query()->findOrFail($data['contact_id']), (int) $data['year'], (int) $data['month'])]);
    }

    /**
     * Show a dossier.
     */
    #[OA\Get(path: '/api/v1/customs/dossiers/{id}', operationId: 'showCustomsDossier', description: 'Return a dossier with charges, quotes, documents, deliveries and declarations.', summary: 'Show dossier', security: [['bearerAuth' => []]], tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsRecordId')], responses: [
        new OA\Response(response: 200, description: 'Dossier', content: new OA\JsonContent(ref: '#/components/schemas/CustomsDossierResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
    ])]
    public function show(string $id): CustomsRecordResource
    {
        $dossier = $this->find($id);
        $this->authorize('view', $dossier);

        return new CustomsRecordResource($dossier->load(['charges', 'quotes.lines', 'documents', 'deliveries', 'declarations']));
    }

    /**
     * Change a dossier.
     */
    #[OA\Put(path: '/api/v1/customs/dossiers/{id}', operationId: 'updateCustomsDossier', description: 'Change a dossier.', summary: 'Change dossier', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CustomsDossierRequest')), tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsRecordId')], responses: [
        new OA\Response(response: 200, description: 'Dossier changed', content: new OA\JsonContent(ref: '#/components/schemas/CustomsDossierResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CustomsUnprocessable', response: 422),
    ])]
    public function update(SaveDossierRequest $request, string $id): CustomsRecordResource
    {
        $dossier = $this->find($id);
        $this->authorize('update', $dossier);

        $dossier->update($request->validated());

        return new CustomsRecordResource($dossier);
    }

    /**
     * Add a cost or a revenue.
     */
    #[OA\Post(path: '/api/v1/customs/dossiers/{id}/charges', operationId: 'storeCustomsDossierCharge', description: 'Add a direct or indirect cost or a revenue to a dossier, in the currency of the dossier.', summary: 'Add dossier charge', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CustomsChargeRequest')), tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsRecordId')], responses: [
        new OA\Response(response: 201, description: 'Charge added', content: new OA\JsonContent(ref: '#/components/schemas/CustomsRecordResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CustomsUnprocessable', response: 422),
    ])]
    public function addCharge(SaveChargeRequest $request, string $id): JsonResponse
    {
        $dossier = $this->find($id);
        $this->authorize('update', $dossier);

        return (new CustomsRecordResource($this->dossiers->addCharge($dossier, $request->validated())))->response()->setStatusCode(201);
    }

    /**
     * Bring duty and VAT into the dossier.
     */
    #[OA\Post(path: '/api/v1/customs/dossiers/{id}/import-assessments', operationId: 'importCustomsDossierAssessments', description: 'Create the duty and import VAT costs from the decisions of the declarations of the dossier. Running it again changes nothing, and a corrected decision updates its charge.', summary: 'Import assessments into dossier', security: [['bearerAuth' => []]], tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsRecordId')], responses: [
        new OA\Response(response: 200, description: 'Charges from assessments', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CustomsUnprocessable', response: 422),
    ])]
    public function importAssessments(string $id): AnonymousResourceCollection
    {
        $dossier = $this->find($id);
        $this->authorize('update', $dossier);

        return CustomsRecordResource::collection($this->dossiers->importAssessments($dossier));
    }

    /**
     * Dossier profitability.
     */
    #[OA\Get(path: '/api/v1/customs/dossiers/{id}/profitability', operationId: 'customsDossierProfitability', description: 'Return revenue, direct and indirect costs, contribution margin and result of a dossier.', summary: 'Dossier profitability', security: [['bearerAuth' => []]], tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsRecordId')], responses: [
        new OA\Response(response: 200, description: 'Profitability', content: new OA\JsonContent(ref: '#/components/schemas/CustomsProfitabilityResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
    ])]
    public function profitability(string $id): JsonResponse
    {
        $dossier = $this->find($id);
        $this->authorize('view', $dossier);

        return response()->json(['data' => $this->dossiers->profitability($dossier)]);
    }

    /**
     * Check billed duty and VAT.
     */
    #[OA\Get(path: '/api/v1/customs/dossiers/{id}/assessment-check', operationId: 'customsDossierAssessmentCheck', description: 'Compare the duty and VAT billed to the customer with the amounts assessed by customs.', summary: 'Check billed duty and VAT', security: [['bearerAuth' => []]], tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsRecordId')], responses: [
        new OA\Response(response: 200, description: 'Comparison', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
    ])]
    public function assessmentCheck(string $id): JsonResponse
    {
        $dossier = $this->find($id);
        $this->authorize('view', $dossier);

        return response()->json(['data' => $this->dossiers->assessmentBillingCheck($dossier)]);
    }

    /**
     * Invoice a dossier.
     */
    #[OA\Post(path: '/api/v1/customs/dossiers/{id}/invoice', operationId: 'invoiceCustomsDossier', description: 'Issue the unbilled revenue of a dossier as one invoice, through the invoicing flow of the application.', summary: 'Invoice dossier', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CustomsInvoiceRequest')), tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsRecordId')], responses: [
        new OA\Response(response: 201, description: 'Invoice issued', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CustomsUnprocessable', response: 422),
    ])]
    public function invoice(InvoiceDossierRequest $request, string $id): JsonResponse
    {
        $dossier = $this->find($id);
        $this->authorize('update', $dossier);

        return response()->json(['data' => $this->dossiers->invoice($dossier, $request->validated('date'))], 201);
    }

    /**
     * Attach a document.
     */
    #[OA\Post(path: '/api/v1/customs/dossiers/{id}/documents', operationId: 'storeCustomsDossierDocument', description: 'Attach an invoice, delivery note, loading list or email to a dossier.', summary: 'Attach dossier document', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(ref: '#/components/schemas/CustomsDossierDocumentRequest'))), tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsRecordId')], responses: [
        new OA\Response(response: 201, description: 'Document attached', content: new OA\JsonContent(ref: '#/components/schemas/CustomsRecordResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CustomsUnprocessable', response: 422),
    ])]
    public function storeDocument(StoreDossierDocumentRequest $request, string $id): JsonResponse
    {
        $dossier = $this->find($id);
        $this->authorize('update', $dossier);

        $document = $this->dossiers->storeDocument($dossier, $this->tenant(), $request->file('file'), $request->validated('document_kind'));

        return (new CustomsRecordResource($document))->response()->setStatusCode(201);
    }

    /**
     * Record a delivery.
     */
    #[OA\Post(path: '/api/v1/customs/dossiers/{id}/deliveries', operationId: 'storeCustomsDossierDelivery', description: 'Record a part of a split delivery. The dossier is delivered when all its packages are.', summary: 'Record dossier delivery', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CustomsDeliveryRequest')), tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsRecordId')], responses: [
        new OA\Response(response: 201, description: 'Delivery recorded', content: new OA\JsonContent(ref: '#/components/schemas/CustomsRecordResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CustomsUnprocessable', response: 422),
    ])]
    public function addDelivery(SaveDeliveryRequest $request, string $id): JsonResponse
    {
        $dossier = $this->find($id);
        $this->authorize('update', $dossier);

        return (new CustomsRecordResource($this->dossiers->addDelivery($dossier, $request->validated())))->response()->setStatusCode(201);
    }

    private function find(string $id): CustomsDossier
    {
        return CustomsDossier::query()->findOrFail($id);
    }
}

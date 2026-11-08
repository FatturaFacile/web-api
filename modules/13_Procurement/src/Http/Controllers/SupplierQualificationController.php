<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Procurement\Enums\SupplierQualificationStatus;
use Modules\Procurement\Http\Controllers\Concerns\ProcurementControllerHelpers;
use Modules\Procurement\Http\Requests\ProcurementPeriodRequest;
use Modules\Procurement\Http\Requests\StoreSupplierDocumentRequest;
use Modules\Procurement\Http\Requests\UpdateSupplierQualificationRequest;
use Modules\Procurement\Http\Resources\SupplierDocumentResource;
use Modules\Procurement\Http\Resources\SupplierResource;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Models\SupplierDocument;
use Modules\Procurement\Services\SupplierPerformanceService;
use Modules\Procurement\Services\SupplierQualificationService;
use OpenApi\Attributes as OA;

/**
 * Supplier onboarding and performance: qualification status, qualification documents with expiry, measured
 * performance (punctuality, quality, price) and evaluations computed from it.
 */
#[OA\Tag(name: 'Procurement Supplier Qualification', description: 'Supplier qualification, documents with expiry and measured performance')]
final class SupplierQualificationController extends Controller
{
    use ProcurementControllerHelpers;

    public function __construct(
        private readonly SupplierQualificationService $qualification,
        private readonly SupplierPerformanceService $performance,
    ) {}

    /**
     * Sets the qualification status of a supplier.
     */
    #[OA\Put(
        path: '/api/v1/procurement/suppliers/{id}/qualification',
        operationId: 'procurementSupplierQualificationUpdate',
        description: 'Sets the qualification status (prospect, in_qualification, qualified, conditional, expired, disqualified) and optional supplier tolerances. Only qualified and conditional suppliers receive orders; qualifying needs every mandatory document valid.',
        summary: 'Update supplier qualification',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['qualification_status'], properties: [
            new OA\Property(property: 'qualification_status', type: 'string', enum: ['prospect', 'in_qualification', 'qualified', 'conditional', 'expired', 'disqualified']),
            new OA\Property(property: 'review_date', type: 'string', format: 'date', nullable: true),
            new OA\Property(property: 'over_receipt_tolerance_percent', type: 'string', nullable: true),
            new OA\Property(property: 'price_tolerance_percent', type: 'string', nullable: true),
            new OA\Property(property: 'notes', type: 'string', nullable: true),
        ])),
        tags: ['Procurement Supplier Qualification'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProcRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Supplier', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProcurementSupplier')])),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ProcForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ProcNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/ProcUnprocessable', response: 422),
        ]
    )]
    public function updateQualification(UpdateSupplierQualificationRequest $request, string $id): SupplierResource
    {
        $supplier = Supplier::query()->findOrFail($id);
        $data = $request->validated();

        $supplier->update(array_intersect_key($data, array_flip(['over_receipt_tolerance_percent', 'price_tolerance_percent'])));
        $updated = $this->qualification->setStatus($supplier, SupplierQualificationStatus::from($data['qualification_status']), $data['review_date'] ?? null, $data['notes'] ?? null);

        return new SupplierResource($updated);
    }

    /**
     * Qualification documents of a supplier.
     */
    #[OA\Get(
        path: '/api/v1/procurement/suppliers/{id}/documents',
        operationId: 'procurementSupplierDocumentsIndex',
        description: 'Qualification documents of the supplier with their expiry, soonest expiry first.',
        summary: 'List supplier documents',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Procurement Supplier Qualification'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProcRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Documents', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProcurementSupplierDocumentResource'))])),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ProcNotFound', response: 404),
        ]
    )]
    public function documents(string $id): AnonymousResourceCollection
    {
        $supplier = Supplier::query()->findOrFail($id);

        return SupplierDocumentResource::collection($supplier->documents()->orderByRaw('expires_at IS NULL')->orderBy('expires_at')->get());
    }

    /**
     * Adds a qualification document.
     */
    #[OA\Post(
        path: '/api/v1/procurement/suppliers/{id}/documents',
        operationId: 'procurementSupplierDocumentsStore',
        description: 'Records a qualification document (type chosen by the company, e.g. a certification or an insurance policy) with its expiry; a mandatory document past expiry blocks new orders.',
        summary: 'Add supplier document',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProcurementSupplierDocumentResource')),
        tags: ['Procurement Supplier Qualification'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProcRecordId')],
        responses: [
            new OA\Response(response: 201, description: 'Document added', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProcurementSupplierDocumentResource')])),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ProcForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ProcNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/ProcUnprocessable', response: 422),
        ]
    )]
    public function storeDocument(StoreSupplierDocumentRequest $request, string $id): JsonResponse
    {
        /** @var array{document_type: string, reference?: ?string, issued_at?: ?string, expires_at?: ?string, is_mandatory?: bool, attachment_id?: ?string, notes?: ?string} $data */
        $data = $request->validated();
        $document = $this->qualification->addDocument(Supplier::query()->findOrFail($id), $data, $this->actorId($request));

        return (new SupplierDocumentResource($document))->response()->setStatusCode(201);
    }

    /**
     * Removes a qualification document.
     */
    #[OA\Delete(
        path: '/api/v1/procurement/supplier-documents/{id}',
        operationId: 'procurementSupplierDocumentsDestroy',
        description: 'Removes a qualification document of a supplier.',
        summary: 'Delete supplier document',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Procurement Supplier Qualification'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProcRecordId')],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ProcForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ProcNotFound', response: 404),
        ]
    )]
    public function destroyDocument(string $id): Response
    {
        SupplierDocument::query()->findOrFail($id)->delete();

        return response()->noContent();
    }

    /**
     * Documents expiring soon.
     */
    #[OA\Get(
        path: '/api/v1/procurement/supplier-documents/expiring',
        operationId: 'procurementSupplierDocumentsExpiring',
        description: 'Documents of every supplier expiring within `days` (default 30), already expired ones included.',
        summary: 'List expiring supplier documents',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Procurement Supplier Qualification'],
        parameters: [new OA\Parameter(name: 'days', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 365, minimum: 0))],
        responses: [
            new OA\Response(response: 200, description: 'Documents', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProcurementSupplierDocumentResource'))])),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ProcUnprocessable', response: 422),
        ]
    )]
    public function expiring(Request $request): AnonymousResourceCollection
    {
        $days = (int) ($request->validate(['days' => ['nullable', 'integer', 'min:0', 'max:365']])['days'] ?? 30);

        return SupplierDocumentResource::collection($this->qualification->expiringDocuments($days));
    }

    /**
     * Expires the qualification of suppliers with an expired mandatory document.
     */
    #[OA\Post(
        path: '/api/v1/procurement/supplier-qualifications/expire',
        operationId: 'procurementSupplierQualificationsExpire',
        description: 'Marks expired the qualification of every qualified supplier with a mandatory document past expiry; returns how many changed.',
        summary: 'Expire supplier qualifications',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Procurement Supplier Qualification'],
        responses: [
            new OA\Response(response: 200, description: 'Count', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [new OA\Property(property: 'expired', type: 'integer')], type: 'object')])),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ProcForbidden', response: 403),
        ]
    )]
    public function expire(): JsonResponse
    {
        return response()->json(['data' => ['expired' => $this->qualification->expireQualifications()]]);
    }

    /**
     * Measured performance of a supplier in a period.
     */
    #[OA\Get(
        path: '/api/v1/procurement/suppliers/{id}/performance',
        operationId: 'procurementSupplierPerformance',
        description: 'Delivery punctuality, quality acceptance and price competitiveness of the period computed from receipts, inspections, returns and orders; overall score is their average, rating A from 90, B from 75, C from 60, D below.',
        summary: 'Show supplier performance',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Procurement Supplier Qualification'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProcRecordId'), new OA\Parameter(ref: '#/components/parameters/ProcDateFrom'), new OA\Parameter(ref: '#/components/parameters/ProcDateTo')],
        responses: [
            new OA\Response(response: 200, description: 'Metrics', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ProcNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/ProcUnprocessable', response: 422),
        ]
    )]
    public function performance(ProcurementPeriodRequest $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->performance->metrics(Supplier::query()->findOrFail($id), $request->from(), $request->to())]);
    }

    /**
     * Records an evaluation from the measured performance.
     */
    #[OA\Post(
        path: '/api/v1/procurement/suppliers/{id}/evaluations/compute',
        operationId: 'procurementSupplierEvaluationCompute',
        description: 'Records a supplier evaluation of the period with the computed metrics and updates the rating of the supplier.',
        summary: 'Compute supplier evaluation',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'from', type: 'string', format: 'date'),
            new OA\Property(property: 'to', type: 'string', format: 'date'),
            new OA\Property(property: 'comments', type: 'string', nullable: true),
        ])),
        tags: ['Procurement Supplier Qualification'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProcRecordId')],
        responses: [
            new OA\Response(response: 201, description: 'Evaluation recorded', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ProcForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ProcNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/ProcUnprocessable', response: 422),
        ]
    )]
    public function computeEvaluation(ProcurementPeriodRequest $request, string $id): JsonResponse
    {
        $evaluation = $this->performance->evaluate(Supplier::query()->findOrFail($id), $request->from(), $request->to(), (string) $this->actorId($request), $request->validated('comments'));

        return response()->json(['data' => [
            'id' => (string) $evaluation->id,
            'supplier_id' => (string) $evaluation->supplier_id,
            'evaluation_period_start' => $request->from(),
            'evaluation_period_end' => $request->to(),
            'on_time_delivery_rate' => (string) $evaluation->on_time_delivery_rate,
            'quality_acceptance_rate' => (string) $evaluation->quality_acceptance_rate,
            'price_competitiveness_score' => (string) $evaluation->price_competitiveness_score,
            'overall_rating' => $evaluation->overall_rating,
        ]], 201);
    }
}

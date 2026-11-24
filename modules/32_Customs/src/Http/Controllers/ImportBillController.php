<?php

declare(strict_types=1);

namespace Modules\Customs\Http\Controllers;

use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Customs\Http\Requests\SaveImportBillRequest;
use Modules\Customs\Http\Resources\CustomsRecordResource;
use Modules\Customs\Models\CustomsImportBill;
use Modules\Customs\Services\ImportBillService;
use OpenApi\Attributes as OA;

/**
 * Import customs bills (bollette doganali di importazione).
 */
final class ImportBillController extends CustomsController
{
    public function __construct(TenantContext $tenantContext, private readonly ImportBillService $bills)
    {
        parent::__construct($tenantContext);
    }

    /**
     * List import customs bills.
     */
    #[OA\Get(path: '/api/v1/customs/import-bills', operationId: 'listCustomsImportBills', description: 'Import customs bills, newest first.', summary: 'List import customs bills', security: [['bearerAuth' => []]], tags: ['Customs'], responses: [
        new OA\Response(response: 200, description: 'Bills', content: new OA\JsonContent(type: 'object')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CustomsImportBill::class);

        return CustomsRecordResource::collection(CustomsImportBill::query()->orderByDesc('bill_date')->paginate($this->perPage($request)));
    }

    /**
     * Record an import customs bill.
     */
    #[OA\Post(path: '/api/v1/customs/import-bills', operationId: 'createCustomsImportBill', description: 'Record the bill with its customs value, non-taxable duty and import VAT. The VAT is computed on value plus duty plus ancillary costs when not given.', summary: 'Create import customs bill', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')), tags: ['Customs'], responses: [
        new OA\Response(response: 201, description: 'Bill', content: new OA\JsonContent(ref: '#/components/schemas/CustomsRecordResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsUnprocessable', response: 422),
    ])]
    public function store(SaveImportBillRequest $request): JsonResponse
    {
        $this->authorize('create', CustomsImportBill::class);

        return (new CustomsRecordResource($this->bills->create($request->validated(), $this->actorId($request))))->response()->setStatusCode(201);
    }

    /**
     * Show an import customs bill.
     */
    #[OA\Get(path: '/api/v1/customs/import-bills/{id}', operationId: 'showCustomsImportBill', description: 'One import customs bill.', summary: 'Show import customs bill', security: [['bearerAuth' => []]], tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsRecordId')], responses: [
        new OA\Response(response: 200, description: 'Bill', content: new OA\JsonContent(ref: '#/components/schemas/CustomsRecordResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
    ])]
    public function show(string $id): CustomsRecordResource
    {
        $bill = CustomsImportBill::query()->findOrFail($id);
        $this->authorize('view', $bill);

        return new CustomsRecordResource($bill);
    }

    /**
     * Reconcile a bill with the broker invoice.
     */
    #[OA\Get(path: '/api/v1/customs/import-bills/{id}/reconciliation', operationId: 'reconcileCustomsImportBill', description: 'Duty plus import VAT of the bill against the total of the invoice of the customs broker.', summary: 'Reconcile import customs bill', security: [['bearerAuth' => []]], tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsRecordId')], responses: [
        new OA\Response(response: 200, description: 'Reconciliation', content: new OA\JsonContent(type: 'object')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
    ])]
    public function reconciliation(string $id): JsonResponse
    {
        $bill = CustomsImportBill::query()->findOrFail($id);
        $this->authorize('view', $bill);

        return response()->json(['data' => $this->bills->reconcile($bill)]);
    }

    /**
     * Post an import customs bill.
     */
    #[OA\Post(path: '/api/v1/customs/import-bills/{id}/post', operationId: 'postCustomsImportBill', description: 'Post duty and import VAT through the General Ledger. Posting twice changes nothing.', summary: 'Post import customs bill', security: [['bearerAuth' => []]], tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsRecordId')], responses: [
        new OA\Response(response: 200, description: 'Bill', content: new OA\JsonContent(ref: '#/components/schemas/CustomsRecordResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CustomsUnprocessable', response: 422),
    ])]
    public function post(Request $request, string $id): CustomsRecordResource
    {
        $bill = CustomsImportBill::query()->findOrFail($id);
        $this->authorize('post', $bill);

        return new CustomsRecordResource($this->bills->post($bill, $this->tenant(), $this->actorId($request)));
    }
}

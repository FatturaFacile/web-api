<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\ServiceManagement\Actions\LookupSerialWarrantyAndIssueRmaAction;
use Modules\ServiceManagement\Actions\ProcessBenchDiagnosisAndRepairQuoteAction;
use Modules\ServiceManagement\Contracts\RmaServiceInterface;
use Modules\ServiceManagement\DTOs\BenchDiagnosisDTO;
use Modules\ServiceManagement\DTOs\CompleteRmaRepairDTO;
use Modules\ServiceManagement\DTOs\IssueRmaDTO;
use Modules\ServiceManagement\DTOs\ReceiveRmaDTO;
use Modules\ServiceManagement\DTOs\ReturnRmaDTO;
use Modules\ServiceManagement\Http\Requests\CancelRmaRequest;
use Modules\ServiceManagement\Http\Requests\CompleteRmaRepairRequest;
use Modules\ServiceManagement\Http\Requests\ReceiveRmaRequest;
use Modules\ServiceManagement\Http\Requests\ReturnRmaRequest;
use Modules\ServiceManagement\Http\Requests\RmaDiagnosisRequest;
use Modules\ServiceManagement\Http\Requests\RmaEstimateDecisionRequest;
use Modules\ServiceManagement\Http\Requests\StoreRmaOrderRequest;
use Modules\ServiceManagement\Http\Resources\RmaOrderResource;
use Modules\ServiceManagement\Models\DepotRepairRmaOrder;
use Modules\ServiceManagement\Services\RmaService;
use OpenApi\Attributes as OA;

final class RmaOrderController extends Controller
{
    public function __construct(
        private readonly RmaServiceInterface $rmaService,
        private readonly LookupSerialWarrantyAndIssueRmaAction $issueAction,
        private readonly ProcessBenchDiagnosisAndRepairQuoteAction $diagnosisAction,
        private readonly TenantContext $tenantContext,
    ) {}

    #[OA\Get(
        path: '/api/v1/esm/rma-orders',
        operationId: 'listEsmRmaOrders',
        description: 'Paginated list of depot-repair RMA orders.',
        summary: 'List RMA orders',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM RMA Orders'],
        parameters: [new OA\Parameter(name: 'status', in: 'query', required: false, description: 'RMA status', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'warranty_status', in: 'query', required: false, description: 'Warranty status', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'customer_id', in: 'query', required: false, description: 'Customer', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'serial_number', in: 'query', required: false, description: 'Exact serial number', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Matches RMA number, symptom or serial number', schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Paginated RMA orders', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')), new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'last_page', type: 'integer'), new OA\Property(property: 'per_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', DepotRepairRmaOrder::class);

        $rmas = DepotRepairRmaOrder::query()
            ->with(['passport', 'customer', 'lines', 'history', 'vendorClaims.supplier', 'loaner.rmaOrder'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->query('status')))
            ->when($request->filled('warranty_status'), fn ($query) => $query->where('warranty_status', $request->query('warranty_status')))
            ->when($request->filled('customer_id'), fn ($query) => $query->where('customer_id', $request->query('customer_id')))
            ->when($request->filled('serial_number'), fn ($query) => $query->whereHas(
                'passport',
                fn ($passport) => $passport->where('serial_number', (string) $request->query('serial_number')),
            ))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.trim((string) $request->query('search')).'%';
                $query->where(function ($inner) use ($term): void {
                    $inner->where('rma_number', 'like', $term)
                        ->orWhere('reported_symptom', 'like', $term)
                        ->orWhereHas('passport', fn ($passport) => $passport->where('serial_number', 'like', $term));
                });
            })
            ->orderByDesc('created_at')
            ->paginate(25);

        return response()->json([
            'data' => RmaOrderResource::collection($rmas->getCollection()),
            'meta' => [
                'current_page' => $rmas->currentPage(),
                'last_page' => $rmas->lastPage(),
                'per_page' => $rmas->perPage(),
                'total' => $rmas->total(),
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/esm/rma-orders',
        operationId: 'createEsmRmaOrder',
        description: 'Looks up the serial warranty and issues an RMA with its shipping label.',
        summary: 'Issue RMA order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM RMA Orders'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['reported_symptom'], properties: [new OA\Property(property: 'serial_number', type: 'string', nullable: true), new OA\Property(property: 'equipment_passport_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'product_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'reported_symptom', type: 'string'), new OA\Property(property: 'preauthorized_threshold', type: 'number', nullable: true), new OA\Property(property: 'ddt_causale_outbound', type: 'string', nullable: true), new OA\Property(property: 'goodwill', type: 'boolean', nullable: true)])),
        responses: [new OA\Response(response: 201, description: 'RMA issued', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object', description: 'RMA order plus label')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
    )]
    public function store(StoreRmaOrderRequest $request): JsonResponse
    {
        $this->authorize('create', DepotRepairRmaOrder::class);
        if ($request->boolean('goodwill')) {
            Gate::authorize('grantGoodwill', DepotRepairRmaOrder::class);
        }

        $tenant = $this->tenant();
        $result = $this->issueAction->execute(
            IssueRmaDTO::fromValidated($tenant->id, $tenant->id, $request->validated()),
            $request->user()?->id,
        );

        return response()->json([
            'message' => 'RMA issued successfully',
            'data' => array_merge(
                (new RmaOrderResource($result['rma']))->resolve($request),
                ['label' => $result['label']],
            ),
        ], 201);
    }

    #[OA\Get(
        path: '/api/v1/esm/rma-orders/{rmaOrder}',
        operationId: 'showEsmRmaOrder',
        description: 'Returns one RMA order.',
        summary: 'Show RMA order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM RMA Orders'],
        parameters: [new OA\Parameter(name: 'rmaOrder', in: 'path', required: true, description: 'Identifier of the RMA order', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'RMA order', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
    )]
    public function show(DepotRepairRmaOrder $rmaOrder): JsonResponse
    {
        $this->authorize('view', $rmaOrder);

        return response()->json(['data' => new RmaOrderResource($rmaOrder)]);
    }

    #[OA\Get(
        path: '/api/v1/esm/rma-orders/{rmaOrder}/label',
        operationId: 'getEsmRmaOrderLabel',
        description: 'Returns the printable RMA label data.',
        summary: 'Get RMA label',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM RMA Orders'],
        parameters: [new OA\Parameter(name: 'rmaOrder', in: 'path', required: true, description: 'Identifier of the RMA order', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Label data', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
    )]
    public function label(DepotRepairRmaOrder $rmaOrder): JsonResponse
    {
        $this->authorize('view', $rmaOrder);

        return response()->json(['data' => $this->rmaService->label($rmaOrder)]);
    }

    #[OA\Post(
        path: '/api/v1/esm/rma-orders/{rmaOrder}/tracking-token',
        operationId: 'regenerateEsmRmaOrderTrackingToken',
        description: 'Invalidates the customer tracking link and issues a new one; the raw token is returned only once.',
        summary: 'Regenerate tracking token',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM RMA Orders'],
        parameters: [new OA\Parameter(name: 'rmaOrder', in: 'path', required: true, description: 'Identifier of the RMA order', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'RMA updated', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 422, description: 'Validation error or invalid state transition', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
    )]
    public function regenerateTrackingToken(Request $request, DepotRepairRmaOrder $rmaOrder): JsonResponse
    {
        $this->authorize('update', $rmaOrder);

        $raw = $this->rmaService->regenerateTrackingToken($rmaOrder, $request->user()?->id);

        return response()->json([
            'message' => 'Tracking link regenerated successfully',
            'data' => $this->rmaService->label($rmaOrder, $raw),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/esm/rma-orders/{rmaOrder}/receive',
        operationId: 'receiveEsmRmaOrder',
        description: 'Registers arrival of the unit at the depot with condition notes and photos.',
        summary: 'Receive RMA unit',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM RMA Orders'],
        parameters: [new OA\Parameter(name: 'rmaOrder', in: 'path', required: true, description: 'Identifier of the RMA order', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'received_at', type: 'string', format: 'date-time', nullable: true), new OA\Property(property: 'receipt_condition_notes', type: 'string', nullable: true), new OA\Property(property: 'receipt_photos', type: 'array', nullable: true, items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'url', type: 'string', nullable: true), new OA\Property(property: 'asset_id', type: 'string', nullable: true), new OA\Property(property: 'caption', type: 'string', nullable: true)]))])),
        responses: [new OA\Response(response: 200, description: 'RMA updated', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 422, description: 'Validation error or invalid state transition', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
    )]
    public function receive(ReceiveRmaRequest $request, DepotRepairRmaOrder $rmaOrder): JsonResponse
    {
        $this->authorize('transition', $rmaOrder);

        $rma = $this->rmaService->receive($rmaOrder, ReceiveRmaDTO::fromValidated($request->validated()), $request->user()?->id);

        return $this->respond('RMA unit received', $rma);
    }

    #[OA\Post(
        path: '/api/v1/esm/rma-orders/{rmaOrder}/diagnosis',
        operationId: 'diagnoseEsmRmaOrder',
        description: 'Records the bench diagnosis and builds the repair estimate lines; may issue a customer estimate link.',
        summary: 'Record bench diagnosis',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM RMA Orders'],
        parameters: [new OA\Parameter(name: 'rmaOrder', in: 'path', required: true, description: 'Identifier of the RMA order', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['lab_bench_diagnostic_notes', 'repair_feasible', 'lines'], properties: [new OA\Property(property: 'lab_bench_diagnostic_notes', type: 'string'), new OA\Property(property: 'repair_feasible', type: 'boolean'), new OA\Property(property: 'parts_available', type: 'boolean', nullable: true), new OA\Property(property: 'goodwill', type: 'boolean', nullable: true), new OA\Property(property: 'lines', type: 'array', items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'line_type', type: 'string', enum: ['part', 'labour']), new OA\Property(property: 'product_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'description', type: 'string'), new OA\Property(property: 'qty', type: 'number'), new OA\Property(property: 'unit_cost', type: 'number'), new OA\Property(property: 'unit_price', type: 'number'), new OA\Property(property: 'defective_part_serial', type: 'string', nullable: true), new OA\Property(property: 'supplier_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'replacement_part_serial', type: 'string', nullable: true)]))])),
        responses: [new OA\Response(response: 200, description: 'RMA updated', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object', description: 'RMA order; estimate_url and estimate_token are present once, only when a customer link is issued')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 422, description: 'Validation error or invalid state transition', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
    )]
    public function diagnosis(RmaDiagnosisRequest $request, DepotRepairRmaOrder $rmaOrder): JsonResponse
    {
        $this->authorize('transition', $rmaOrder);
        if ($request->boolean('goodwill')) {
            Gate::authorize('grantGoodwill', DepotRepairRmaOrder::class);
        }

        $result = $this->diagnosisAction->execute($rmaOrder, BenchDiagnosisDTO::fromValidated($request->validated()), $request->user()?->id);

        $extra = $result['estimate_token'] !== null
            ? ['estimate_url' => $result['estimate_url'], 'estimate_token' => $result['estimate_token']]
            : [];

        return $this->respond('Bench diagnosis recorded', $result['rma'], $extra, $request);
    }

    #[OA\Post(
        path: '/api/v1/esm/rma-orders/{rmaOrder}/estimate/approve',
        operationId: 'approveEsmRmaOrderEstimate',
        description: 'Records customer approval of the estimate on behalf of the customer.',
        summary: 'Approve estimate (staff)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM RMA Orders'],
        parameters: [new OA\Parameter(name: 'rmaOrder', in: 'path', required: true, description: 'Identifier of the RMA order', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'note', type: 'string', nullable: true)])),
        responses: [new OA\Response(response: 200, description: 'RMA updated', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 422, description: 'Validation error or invalid state transition', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
    )]
    public function approveEstimate(RmaEstimateDecisionRequest $request, DepotRepairRmaOrder $rmaOrder): JsonResponse
    {
        $this->authorize('transition', $rmaOrder);

        $rma = $this->rmaService->approveEstimate(
            $rmaOrder,
            RmaService::DECIDED_VIA_STAFF,
            $request->user()?->id,
            $request->user()?->name,
            $request->ip(),
            $request->validated('note'),
        );

        return $this->respond('Estimate approved', $rma);
    }

    #[OA\Post(
        path: '/api/v1/esm/rma-orders/{rmaOrder}/estimate/decline',
        operationId: 'declineEsmRmaOrderEstimate',
        description: 'Records customer refusal of the estimate; the unit is returned unrepaired.',
        summary: 'Decline estimate (staff)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM RMA Orders'],
        parameters: [new OA\Parameter(name: 'rmaOrder', in: 'path', required: true, description: 'Identifier of the RMA order', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'note', type: 'string', nullable: true)])),
        responses: [new OA\Response(response: 200, description: 'RMA updated', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 422, description: 'Validation error or invalid state transition', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
    )]
    public function declineEstimate(RmaEstimateDecisionRequest $request, DepotRepairRmaOrder $rmaOrder): JsonResponse
    {
        $this->authorize('transition', $rmaOrder);

        $rma = $this->rmaService->declineEstimate(
            $rmaOrder,
            RmaService::DECIDED_VIA_STAFF,
            $request->user()?->id,
            $request->user()?->name,
            $request->ip(),
            $request->validated('note'),
        );

        return $this->respond('Estimate declined', $rma);
    }

    #[OA\Post(
        path: '/api/v1/esm/rma-orders/{rmaOrder}/estimate/resend',
        operationId: 'resendEsmRmaOrderEstimate',
        description: 'Re-issues the customer estimate link; the raw token is returned only once.',
        summary: 'Resend estimate link',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM RMA Orders'],
        parameters: [new OA\Parameter(name: 'rmaOrder', in: 'path', required: true, description: 'Identifier of the RMA order', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'RMA updated', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object', description: 'RMA order; estimate_url and estimate_token are present once, only when a customer link is issued')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 422, description: 'Validation error or invalid state transition', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
    )]
    public function resendEstimate(Request $request, DepotRepairRmaOrder $rmaOrder): JsonResponse
    {
        $this->authorize('transition', $rmaOrder);

        $result = $this->rmaService->resendEstimate($rmaOrder, $request->user()?->id);

        return $this->respond('Estimate link re-issued', $result['rma'], [
            'estimate_url' => $this->rmaService->estimateUrl($result['estimate_token']),
            'estimate_token' => $result['estimate_token'],
        ], $request);
    }

    #[OA\Post(
        path: '/api/v1/esm/rma-orders/{rmaOrder}/parts-received',
        operationId: 'markEsmRmaOrderPartsReceived',
        description: 'Moves the RMA out of the awaiting-parts state.',
        summary: 'Mark spare parts received',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM RMA Orders'],
        parameters: [new OA\Parameter(name: 'rmaOrder', in: 'path', required: true, description: 'Identifier of the RMA order', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'RMA updated', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 422, description: 'Validation error or invalid state transition', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
    )]
    public function partsReceived(Request $request, DepotRepairRmaOrder $rmaOrder): JsonResponse
    {
        $this->authorize('transition', $rmaOrder);

        return $this->respond('Spare parts received', $this->rmaService->markPartsReceived($rmaOrder, $request->user()?->id));
    }

    #[OA\Post(
        path: '/api/v1/esm/rma-orders/{rmaOrder}/complete-repair',
        operationId: 'completeEsmRmaOrderRepair',
        description: 'Completes the repair, recording replaced components, firmware and test notes.',
        summary: 'Complete repair',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM RMA Orders'],
        parameters: [new OA\Parameter(name: 'rmaOrder', in: 'path', required: true, description: 'Identifier of the RMA order', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'replaced_components', type: 'array', nullable: true, items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'old_component_id', type: 'string', format: 'uuid'), new OA\Property(property: 'new_component_serial', type: 'string'), new OA\Property(property: 'component_product_id', type: 'string', format: 'uuid', nullable: true)])), new OA\Property(property: 'installed_firmware_version', type: 'string', nullable: true), new OA\Property(property: 'test_notes', type: 'string', nullable: true)])),
        responses: [new OA\Response(response: 200, description: 'RMA updated', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 422, description: 'Validation error or invalid state transition', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
    )]
    public function completeRepair(CompleteRmaRepairRequest $request, DepotRepairRmaOrder $rmaOrder): JsonResponse
    {
        $this->authorize('transition', $rmaOrder);

        $rma = $this->rmaService->completeRepair($rmaOrder, CompleteRmaRepairDTO::fromValidated($request->validated()), $request->user()?->id);

        return $this->respond('Repair completed', $rma);
    }

    #[OA\Post(
        path: '/api/v1/esm/rma-orders/{rmaOrder}/return',
        operationId: 'returnEsmRmaOrderToCustomer',
        description: 'Ships the unit back to the customer.',
        summary: 'Return unit to customer',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM RMA Orders'],
        parameters: [new OA\Parameter(name: 'rmaOrder', in: 'path', required: true, description: 'Identifier of the RMA order', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'returned_at', type: 'string', format: 'date-time', nullable: true), new OA\Property(property: 'ddt_causale_return', type: 'string', nullable: true), new OA\Property(property: 'vat_exempt_art15_dpr633', type: 'boolean', nullable: true)])),
        responses: [new OA\Response(response: 200, description: 'RMA updated', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 422, description: 'Validation error or invalid state transition', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
    )]
    public function returnToCustomer(ReturnRmaRequest $request, DepotRepairRmaOrder $rmaOrder): JsonResponse
    {
        $this->authorize('transition', $rmaOrder);

        $rma = $this->rmaService->returnToCustomer($rmaOrder, ReturnRmaDTO::fromValidated($request->validated()), $request->user()?->id);

        return $this->respond('Unit returned to customer', $rma);
    }

    #[OA\Post(
        path: '/api/v1/esm/rma-orders/{rmaOrder}/close',
        operationId: 'closeEsmRmaOrder',
        description: 'Closes a returned RMA order.',
        summary: 'Close RMA order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM RMA Orders'],
        parameters: [new OA\Parameter(name: 'rmaOrder', in: 'path', required: true, description: 'Identifier of the RMA order', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'RMA updated', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 422, description: 'Validation error or invalid state transition', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
    )]
    public function close(Request $request, DepotRepairRmaOrder $rmaOrder): JsonResponse
    {
        $this->authorize('transition', $rmaOrder);

        return $this->respond('RMA closed', $this->rmaService->close($rmaOrder, $request->user()?->id));
    }

    #[OA\Post(
        path: '/api/v1/esm/rma-orders/{rmaOrder}/cancel',
        operationId: 'cancelEsmRmaOrder',
        description: 'Cancels the RMA order with a mandatory reason.',
        summary: 'Cancel RMA order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM RMA Orders'],
        parameters: [new OA\Parameter(name: 'rmaOrder', in: 'path', required: true, description: 'Identifier of the RMA order', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['reason'], properties: [new OA\Property(property: 'reason', type: 'string')])),
        responses: [new OA\Response(response: 200, description: 'RMA updated', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 422, description: 'Validation error or invalid state transition', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
    )]
    public function cancel(CancelRmaRequest $request, DepotRepairRmaOrder $rmaOrder): JsonResponse
    {
        $this->authorize('transition', $rmaOrder);

        $rma = $this->rmaService->cancel($rmaOrder, (string) $request->validated('reason'), $request->user()?->id);

        return $this->respond('RMA cancelled', $rma);
    }

    /**
     * @param  array<string, mixed>  $extra  one-time fields (raw tokens) merged into `data`
     */
    private function respond(string $message, DepotRepairRmaOrder $rma, array $extra = [], ?Request $request = null): JsonResponse
    {
        $resource = new RmaOrderResource($rma);

        return response()->json([
            'message' => $message,
            'data' => $extra === [] ? $resource : array_merge($resource->resolve($request ?? request()), $extra),
        ]);
    }

    private function tenant(): Tenant
    {
        $tenant = $this->tenantContext->getTenant();
        abort_if($tenant === null, 403, 'No active tenant.');

        return $tenant;
    }
}

<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\ServiceManagement\DTOs\IssueLoanerDTO;
use Modules\ServiceManagement\Enums\LoanerRegisterType;
use Modules\ServiceManagement\Enums\LoanerStatus;
use Modules\ServiceManagement\Enums\RmaStatus;
use Modules\ServiceManagement\Http\Requests\ReturnLoanerRequest;
use Modules\ServiceManagement\Http\Requests\StoreLoanerRequest;
use Modules\ServiceManagement\Http\Resources\LoanerResource;
use Modules\ServiceManagement\Models\DepotRepairRmaOrder;
use Modules\ServiceManagement\Models\Loaner;
use Modules\ServiceManagement\Services\LoanerService;
use OpenApi\Attributes as OA;

final class LoanerController extends Controller
{
    public function __construct(
        private readonly LoanerService $loanerService,
    ) {}

    #[OA\Get(
        path: '/api/v1/esm/loaners',
        operationId: 'listEsmLoaners',
        description: 'Paginated loaner and repair-account register; overdue=true lists loans not returned after the grace period.',
        summary: 'List loaner register',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Loaners'],
        parameters: [new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Loaner status', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'register_type', in: 'query', required: false, description: 'Register type', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'rma_order_id', in: 'query', required: false, description: 'RMA order', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'overdue', in: 'query', required: false, description: 'Only overdue loans', schema: new OA\Schema(type: 'boolean'))],
        responses: [new OA\Response(response: 200, description: 'Paginated loaners', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')), new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'last_page', type: 'integer'), new OA\Property(property: 'per_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Loaner::class);

        $cutoff = now()->subDays((int) config('service-management.rma.loaner_grace_days', 7));

        $loaners = Loaner::query()
            ->with('rmaOrder')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->query('status')))
            ->when($request->filled('register_type'), fn ($query) => $query->where('register_type', $request->query('register_type')))
            ->when($request->filled('rma_order_id'), fn ($query) => $query->where('rma_order_id', $request->query('rma_order_id')))
            ->when($request->boolean('overdue'), fn ($query) => $query
                ->where('register_type', LoanerRegisterType::PrestitoUso->value)
                ->where('status', LoanerStatus::Issued->value)
                ->whereHas('rmaOrder', fn ($rma) => $rma
                    ->whereIn('status', [RmaStatus::Returned->value, RmaStatus::Closed->value, RmaStatus::Cancelled->value])
                    ->where(fn ($inner) => $inner
                        ->where('returned_at', '<', $cutoff)
                        ->orWhere(fn ($fallback) => $fallback->whereNull('returned_at')->where('updated_at', '<', $cutoff))
                    )
                )
            )
            ->orderByDesc('issued_at')
            ->paginate(25);

        return response()->json([
            'data' => LoanerResource::collection($loaners->getCollection()),
            'meta' => [
                'current_page' => $loaners->currentPage(),
                'last_page' => $loaners->lastPage(),
                'per_page' => $loaners->perPage(),
                'total' => $loaners->total(),
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/esm/rma-orders/{rmaOrder}/loaners',
        operationId: 'createEsmRmaOrderLoaner',
        description: 'Registers a loaner unit issued to the customer for this RMA order.',
        summary: 'Issue loaner for RMA',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Loaners'],
        parameters: [new OA\Parameter(name: 'rmaOrder', in: 'path', required: true, description: 'Identifier of the RMA order', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['register_type'], properties: [new OA\Property(property: 'loaner_serial_number', type: 'string', nullable: true), new OA\Property(property: 'loaner_stock_serial_number_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'loaner_product_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'register_type', type: 'string'), new OA\Property(property: 'issued_at', type: 'string', format: 'date-time', nullable: true), new OA\Property(property: 'expected_return_at', type: 'string', format: 'date-time', nullable: true), new OA\Property(property: 'ddt_causale', type: 'string', nullable: true), new OA\Property(property: 'notes', type: 'string', nullable: true)])),
        responses: [new OA\Response(response: 201, description: 'Loaner registered', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
    )]
    public function store(StoreLoanerRequest $request, DepotRepairRmaOrder $rmaOrder): JsonResponse
    {
        $this->authorize('issueLoaner', $rmaOrder);

        $loaner = $this->loanerService->issue($rmaOrder, IssueLoanerDTO::fromValidated($request->validated()), $request->user()?->id);

        return response()->json([
            'message' => 'Loaner register entry created successfully',
            'data' => new LoanerResource($loaner),
        ], 201);
    }

    #[OA\Post(
        path: '/api/v1/esm/loaners/{loaner}/return',
        operationId: 'returnEsmLoaner',
        description: 'Marks the loaner as returned.',
        summary: 'Mark loaner returned',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Loaners'],
        parameters: [new OA\Parameter(name: 'loaner', in: 'path', required: true, description: 'Identifier of the loaner register entry', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'returned_at', type: 'string', format: 'date-time', nullable: true)])),
        responses: [new OA\Response(response: 200, description: 'Loaner returned', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
    )]
    public function markReturned(ReturnLoanerRequest $request, Loaner $loaner): JsonResponse
    {
        $this->authorize('update', $loaner);

        $updated = $this->loanerService->markReturned($loaner, $request->validated('returned_at'));

        return response()->json([
            'message' => 'Loaner returned successfully',
            'data' => new LoanerResource($updated),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/esm/loaners/{loaner}/mark-lost',
        operationId: 'markEsmLoanerLost',
        description: 'Marks the loaner as lost.',
        summary: 'Mark loaner lost',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Loaners'],
        parameters: [new OA\Parameter(name: 'loaner', in: 'path', required: true, description: 'Identifier of the loaner register entry', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Loaner marked as lost', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
    )]
    public function markLost(Loaner $loaner): JsonResponse
    {
        $this->authorize('update', $loaner);

        $updated = $this->loanerService->markLost($loaner);

        return response()->json([
            'message' => 'Loaner marked as lost',
            'data' => new LoanerResource($updated),
        ]);
    }
}

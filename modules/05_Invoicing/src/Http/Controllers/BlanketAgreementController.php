<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Invoicing\Http\Requests\BlanketAgreementRequest;
use Modules\Invoicing\Http\Requests\CallOffRequest;
use Modules\Invoicing\Http\Resources\BlanketAgreementResource;
use Modules\Invoicing\Http\Resources\WorkspaceSalesOrderResource;
use Modules\Invoicing\Models\BlanketAgreement;
use Modules\Invoicing\Services\BlanketAgreementService;
use OpenApi\Attributes as OA;

/**
 * Blanket agreements (call-off contracts): CRUD of the drafts, life cycle and the call-off orders released against them.
 */
final class BlanketAgreementController extends Controller
{
    public function __construct(private readonly BlanketAgreementService $service, private readonly TenantContext $tenantContext) {}

    #[OA\Get(
        path: '/api/v1/blanket-agreements',
        operationId: 'listBlanketAgreements',
        description: 'Paginated blanket agreements of the company, filterable by customer, status and expiry.',
        summary: 'List blanket agreements',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Blanket Agreements'],
        parameters: [
            new OA\Parameter(name: 'contact_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'expiring_within_days', in: 'query', required: false, description: 'Active agreements ending within that many days', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = BlanketAgreement::query()->with('customer')
            ->when($request->query('contact_id'), static fn ($q, $value) => $q->where('contact_id', (string) $value))
            ->when($request->query('status'), static fn ($q, $value) => $q->where('status', (string) $value))
            ->when($request->query('expiring_within_days'), static fn ($q, $days) => $q->where('status', BlanketAgreementService::Active)->whereDate('valid_to', '<=', now()->addDays((int) $days)->toDateString()))
            ->orderByDesc('valid_from');

        return BlanketAgreementResource::collection($query->paginate(min(100, max(1, (int) $request->query('per_page', 25)))));
    }

    #[OA\Post(
        path: '/api/v1/blanket-agreements',
        operationId: 'createBlanketAgreement',
        description: 'Creates a draft blanket agreement with its lines.',
        summary: 'Create blanket agreement',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Blanket Agreements'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
        responses: [
            new OA\Response(response: 201, description: 'Blanket agreement created', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(BlanketAgreementRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $lines = $validated['lines'];
        unset($validated['lines']);

        $agreement = $this->service->create((string) $this->tenantContext->getTenantId(), $validated, $lines, $this->userId($request));

        return (new BlanketAgreementResource($agreement->load('customer')))->response()->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/blanket-agreements/{id}',
        operationId: 'showBlanketAgreement',
        description: 'A blanket agreement with its lines and consumption.',
        summary: 'Get blanket agreement',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Blanket Agreements'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): BlanketAgreementResource
    {
        return new BlanketAgreementResource(BlanketAgreement::query()->with(['customer', 'lines'])->findOrFail($id));
    }

    #[OA\Put(
        path: '/api/v1/blanket-agreements/{id}',
        operationId: 'updateBlanketAgreement',
        description: 'Updates a draft blanket agreement; one in force changes through its life cycle only.',
        summary: 'Update blanket agreement',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Blanket Agreements'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
        responses: [
            new OA\Response(response: 200, description: 'Blanket agreement updated', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function update(BlanketAgreementRequest $request, string $id): BlanketAgreementResource
    {
        $validated = $request->validated();
        $lines = $validated['lines'] ?? null;
        unset($validated['lines']);

        return new BlanketAgreementResource($this->service->update(BlanketAgreement::query()->findOrFail($id), $validated, $lines, $this->userId($request)));
    }

    #[OA\Post(
        path: '/api/v1/blanket-agreements/{id}/activate',
        operationId: 'activateBlanketAgreement',
        description: 'Activates a draft (or resumes a suspended) blanket agreement.',
        summary: 'Activate blanket agreement',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Blanket Agreements'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Blanket agreement activated', content: new OA\JsonContent(type: 'object'))]
    )]
    public function activate(Request $request, string $id): BlanketAgreementResource
    {
        return new BlanketAgreementResource($this->service->activate(BlanketAgreement::query()->findOrFail($id), $this->userId($request)));
    }

    #[OA\Post(
        path: '/api/v1/blanket-agreements/{id}/suspend',
        operationId: 'suspendBlanketAgreement',
        description: 'Suspends an active blanket agreement: no call-off is accepted until it is activated again.',
        summary: 'Suspend blanket agreement',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Blanket Agreements'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Blanket agreement suspended', content: new OA\JsonContent(type: 'object'))]
    )]
    public function suspend(Request $request, string $id): BlanketAgreementResource
    {
        return new BlanketAgreementResource($this->service->suspend(BlanketAgreement::query()->findOrFail($id), $this->userId($request)));
    }

    #[OA\Post(
        path: '/api/v1/blanket-agreements/{id}/close',
        operationId: 'closeBlanketAgreement',
        description: 'Closes a blanket agreement for good.',
        summary: 'Close blanket agreement',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Blanket Agreements'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Blanket agreement closed', content: new OA\JsonContent(type: 'object'))]
    )]
    public function close(Request $request, string $id): BlanketAgreementResource
    {
        return new BlanketAgreementResource($this->service->close(BlanketAgreement::query()->findOrFail($id), $this->userId($request)));
    }

    #[OA\Post(
        path: '/api/v1/blanket-agreements/{id}/renew',
        operationId: 'renewBlanketAgreement',
        description: 'Creates a draft renewal with the same lines and prices and a fresh balance.',
        summary: 'Renew blanket agreement',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Blanket Agreements'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 201, description: 'Renewal created', content: new OA\JsonContent(type: 'object'))]
    )]
    public function renew(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['valid_from' => ['nullable', 'date'], 'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from']]);
        $renewed = $this->service->renew(BlanketAgreement::query()->findOrFail($id), $data, $this->userId($request));

        return (new BlanketAgreementResource($renewed))->response()->setStatusCode(201);
    }

    #[OA\Post(
        path: '/api/v1/blanket-agreements/{id}/call-off',
        operationId: 'callOffBlanketAgreement',
        description: 'Releases a call-off sales order against an active agreement: validates validity and balance, inherits prices, discounts, payment terms and currency, and consumes the balance atomically.',
        summary: 'Create call-off order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Blanket Agreements'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
        responses: [
            new OA\Response(response: 201, description: 'Call-off order created', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 422, description: 'Agreement not active, outside validity or balance exceeded', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function callOff(CallOffRequest $request, string $id): JsonResponse
    {
        $validated = $request->validated();
        $lines = $validated['lines'];
        unset($validated['lines']);

        $order = $this->service->callOff(BlanketAgreement::query()->findOrFail($id), $lines, $validated, $this->userId($request));

        return (new WorkspaceSalesOrderResource($order->load('items')))->response()->setStatusCode(201);
    }

    private function userId(Request $request): ?string
    {
        return $request->user()?->id !== null ? (string) $request->user()->id : null;
    }
}

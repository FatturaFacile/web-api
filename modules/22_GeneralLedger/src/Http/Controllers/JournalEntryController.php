<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Http\Requests\ReverseJournalEntryRequest;
use Modules\GeneralLedger\Http\Requests\StoreJournalEntryRequest;
use Modules\GeneralLedger\Http\Resources\JournalEntryResource;
use Modules\GeneralLedger\Models\JournalEntry;
use OpenApi\Attributes as OA;

final class JournalEntryController extends Controller
{
    public function __construct(
        private readonly DoubleEntryPostingServiceInterface $postingService,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Returns the paginated list of journals of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/journals',
        operationId: 'listGeneralLedgerJournals',
        description: 'Returns the paginated list of journals of the active tenant. Results can be narrowed with the query parameters.',
        summary: 'List journals',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Journals'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Filter by status', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'fiscal_period_id', in: 'query', required: false, description: 'Filter by fiscal period', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Number of items per page', schema: new OA\Schema(type: 'integer', default: 25)),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of journals', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GeneralLedgerJournalEntryResource')), new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'last_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId() ?? $request->user()?->tenant_id;
        if (! $tenantId) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        $entries = JournalEntry::where('tenant_id', $tenantId)
            ->with('lines')
            ->when($request->query('status'), fn ($q, $st) => $q->where('status', $st))
            ->when($request->query('fiscal_period_id'), fn ($q, $fp) => $q->where('fiscal_period_id', $fp))
            ->orderBy('posting_date', 'desc')
            ->paginate((int) $request->query('per_page', 25));

        return response()->json([
            'data' => JournalEntryResource::collection($entries),
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'total' => $entries->total(),
            ],
        ]);
    }

    /**
     * Creates a new journal in the active tenant.
     */
    #[OA\Post(
        path: '/api/v1/general-ledger/journals',
        operationId: 'createGeneralLedgerJournal',
        description: 'Creates a new journal in the active tenant.',
        summary: 'Create journal',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Journals'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GeneralLedgerStoreJournalEntryRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Journal created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerJournalEntryResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StoreJournalEntryRequest $request): JsonResponse
    {
        $this->authorize('create', JournalEntry::class);

        $tenantId = $this->tenantContext->getTenantId() ?? $request->user()?->tenant_id;
        if (! $tenantId) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        $user = $request->user();
        if (! $user) {
            abort(401, 'Unauthenticated');
        }

        $actorId = (string) $user->id;

        $payload = array_merge($request->validated(), [
            'tenant_id' => $tenantId,
        ]);

        $entry = $this->postingService->createAndPost($payload, $actorId);

        return response()->json([
            'message' => 'Journal entry posted successfully',
            'data' => new JournalEntryResource($entry),
        ], 201);
    }

    /**
     * Returns a single journal of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/journals/{journal}',
        operationId: 'getGeneralLedgerJournal',
        description: 'Returns a single journal of the active tenant.',
        summary: 'Get journal',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Journals'],
        parameters: [
            new OA\Parameter(name: 'journal', in: 'path', required: true, description: 'Identifier of the journal', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerJournalEntryResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $entry = JournalEntry::with('lines')->findOrFail($id);
        $this->authorize('view', $entry);

        return response()->json([
            'data' => new JournalEntryResource($entry),
        ]);
    }

    /**
     * Posts a draft journal entry to the ledger.
     */
    #[OA\Post(
        path: '/api/v1/general-ledger/journals/{id}/post',
        operationId: 'postGeneralLedgerJournal',
        description: 'Posts a draft journal entry to the ledger.',
        summary: 'Post journal entry',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Journals'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the journal', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerJournalEntryResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function post(Request $request, string $id): JsonResponse
    {
        $entry = JournalEntry::findOrFail($id);
        $this->authorize('update', $entry);

        $user = $request->user();
        if (! $user) {
            abort(401, 'Unauthenticated');
        }

        $actorId = (string) $user->id;
        $posted = $this->postingService->postDraft($id, $actorId);

        return response()->json([
            'message' => 'Journal entry posted successfully',
            'data' => new JournalEntryResource($posted),
        ]);
    }

    /**
     * Creates the reversal of a posted journal entry at the given date.
     */
    #[OA\Post(
        path: '/api/v1/general-ledger/journals/{id}/reverse',
        operationId: 'reverseGeneralLedgerJournal',
        description: 'Creates the reversal of a posted journal entry at the given date.',
        summary: 'Reverse journal entry',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Journals'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the journal', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GeneralLedgerReverseJournalEntryRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerJournalEntryResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function reverse(ReverseJournalEntryRequest $request, string $id): JsonResponse
    {
        $entry = JournalEntry::findOrFail($id);
        $this->authorize('update', $entry);

        $user = $request->user();
        if (! $user) {
            abort(401, 'Unauthenticated');
        }

        $actorId = (string) $user->id;
        $reversal = $this->postingService->reverseJournal(
            $id,
            $request->validated('reversal_date'),
            $request->validated('reason'),
            $actorId
        );

        return response()->json([
            'message' => 'Journal entry reversed successfully',
            'data' => new JournalEntryResource($reversal),
        ]);
    }

    /**
     * Checks whether the given journal lines balance, without saving anything.
     */
    #[OA\Post(
        path: '/api/v1/general-ledger/journals/validate-balance',
        operationId: 'validateBalanceGeneralLedgerJournal',
        description: 'Checks whether the given journal lines balance, without saving anything.',
        summary: 'Validate journal balance',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Journals'],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'lines', type: 'array', items: new OA\Items)])),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'is_balanced', type: 'boolean')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function validateBalance(Request $request): JsonResponse
    {
        $lines = $request->input('lines', []);
        $isValid = $this->postingService->validateBalance($lines);

        return response()->json([
            'is_balanced' => $isValid,
        ]);
    }
}

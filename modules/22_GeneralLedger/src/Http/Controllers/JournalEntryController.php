<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Http\Requests\RejectJournalEntryRequest;
use Modules\GeneralLedger\Http\Requests\ReverseJournalEntryRequest;
use Modules\GeneralLedger\Http\Requests\StoreJournalEntryRequest;
use Modules\GeneralLedger\Http\Resources\JournalEntryResource;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Services\ManualJournalService;
use OpenApi\Attributes as OA;

final class JournalEntryController extends Controller
{
    public function __construct(
        private readonly DoubleEntryPostingServiceInterface $postingService,
        private readonly TenantContext $tenantContext,
        private readonly ManualJournalService $manualJournals,
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
        description: 'Writes a manual journal (prima nota manuale) through the posting service. Debits and credits must balance exactly; every account must be active, open to manual postings (`allows_manual_posting`) and get a cost centre when it requires one; the posting date must lie in the fiscal period given, which must be open. With `status` posted (default) the journal is posted at once and gets its libro giornale number, unless the tenant requires four-eyes approval for its amount (GET /api/v1/general-ledger/settings): then it is saved as `pending_approval` and posted only when another person approves it. With `auto_reverse_on` the journal is reversed automatically on that date. The entry number is assigned by the system.',
        summary: 'Create manual journal',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Journals'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GeneralLedgerStoreJournalEntryRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Journal posted, saved as draft or waiting for approval (see data.status)', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerJournalEntryResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Read-only role or missing ledger write access', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error, unbalanced lines, account closed to manual postings, or fiscal period closed/locked', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
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

        $entry = $this->manualJournals->create($payload, $actorId);

        return response()->json([
            'message' => match ($entry->status) {
                'pending_approval' => 'Journal entry saved and waiting for approval',
                'draft' => 'Journal entry saved as draft',
                default => 'Journal entry posted successfully',
            },
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
        description: 'Posts a draft journal entry to the ledger. Refused with 409 when the journal needs four-eyes approval (submit it instead) or is not a draft, with 422 when its period is closed or locked or its lines do not balance.',
        summary: 'Post journal entry',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Journals'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the journal', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerJournalEntryResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Read-only role or missing ledger write access', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Approval required or journal not a draft', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Fiscal period closed or locked, or unbalanced lines', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function post(Request $request, string $id): JsonResponse
    {
        $entry = JournalEntry::findOrFail($id);
        $this->authorize('process', $entry);

        $user = $request->user();
        if (! $user) {
            abort(401, 'Unauthenticated');
        }

        $actorId = (string) $user->id;
        $posted = $this->manualJournals->postDraft($id, $actorId);

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
        description: 'Creates the reversal (storno) of a posted journal entry at the given date: the same lines with debit and credit swapped, in the fiscal period of that date, which must be open. The original becomes `reversed`; its open items are closed or reopened accordingly. Refused with 409 when the entry is not posted or is the entry of a posted VAT settlement.',
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
            new OA\Response(response: 403, description: 'Read-only role or missing ledger write access', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Entry not posted, or entry of a posted VAT settlement', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function reverse(ReverseJournalEntryRequest $request, string $id): JsonResponse
    {
        $entry = JournalEntry::findOrFail($id);
        $this->authorize('process', $entry);

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

    /**
     * Sends a draft manual journal to approval (four-eyes).
     */
    #[OA\Post(
        path: '/api/v1/general-ledger/journals/{id}/submit',
        operationId: 'submitGeneralLedgerJournal',
        description: 'Sends a draft journal to approval: it becomes `pending_approval` and can be posted only by the approval of a person other than its author and submitter. Refused with 409 when the journal is not a draft.',
        summary: 'Submit journal for approval',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Journals'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the journal', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Journal waiting for approval', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerJournalEntryResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Read-only role or missing ledger write access', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Journal not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Journal not a draft', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function submit(Request $request, string $id): JsonResponse
    {
        $this->authorize('process', JournalEntry::findOrFail($id));
        $entry = $this->manualJournals->submit($id, (string) $request->user()->id);

        return response()->json(['message' => 'Journal entry submitted for approval', 'data' => new JournalEntryResource($entry)]);
    }

    /**
     * Approves and posts a journal waiting for approval.
     */
    #[OA\Post(
        path: '/api/v1/general-ledger/journals/{id}/approve',
        operationId: 'approveGeneralLedgerJournal',
        description: 'Approves a journal waiting for approval and posts it through the posting service (libro giornale number, period checks). Four-eyes principle: the approver must be a person other than the author and the submitter (403 otherwise). Refused with 409 when the journal is not waiting for approval, with 422 when its period has been closed meanwhile.',
        summary: 'Approve and post journal',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Journals'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the journal', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Journal approved and posted', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerJournalEntryResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Same person as author or submitter, read-only role or missing ledger write access', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Journal not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Journal not waiting for approval', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Fiscal period closed or locked', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function approve(Request $request, string $id): JsonResponse
    {
        $this->authorize('process', JournalEntry::findOrFail($id));
        $entry = $this->manualJournals->approve($id, (string) $request->user()->id);

        return response()->json(['message' => 'Journal entry approved and posted', 'data' => new JournalEntryResource($entry)]);
    }

    /**
     * Rejects a journal waiting for approval.
     */
    #[OA\Post(
        path: '/api/v1/general-ledger/journals/{id}/reject',
        operationId: 'rejectGeneralLedgerJournal',
        description: 'Rejects a journal waiting for approval with a reason. The journal stays as `rejected` for the audit trail and is never posted. Refused with 409 when the journal is not waiting for approval.',
        summary: 'Reject journal',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Journals'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the journal', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reason'], properties: [new OA\Property(property: 'reason', type: 'string', maxLength: 500, example: 'Wrong cost centre: book it on CC-ADMIN')])),
        responses: [
            new OA\Response(response: 200, description: 'Journal rejected', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerJournalEntryResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Read-only role or missing ledger write access', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Journal not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Journal not waiting for approval', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Reason missing', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function reject(RejectJournalEntryRequest $request, string $id): JsonResponse
    {
        $this->authorize('process', JournalEntry::findOrFail($id));
        $entry = $this->manualJournals->reject($id, (string) $request->user()->id, (string) $request->validated('reason'));

        return response()->json(['message' => 'Journal entry rejected', 'data' => new JournalEntryResource($entry)]);
    }
}

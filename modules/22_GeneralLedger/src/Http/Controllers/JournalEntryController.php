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

final class JournalEntryController extends Controller
{
    public function __construct(
        private readonly DoubleEntryPostingServiceInterface $postingService,
        private readonly TenantContext $tenantContext
    ) {}

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

    public function show(string $id): JsonResponse
    {
        $entry = JournalEntry::with('lines')->findOrFail($id);
        $this->authorize('view', $entry);

        return response()->json([
            'data' => new JournalEntryResource($entry),
        ]);
    }

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

    public function validateBalance(Request $request): JsonResponse
    {
        $lines = $request->input('lines', []);
        $isValid = $this->postingService->validateBalance($lines);

        return response()->json([
            'is_balanced' => $isValid,
        ]);
    }
}

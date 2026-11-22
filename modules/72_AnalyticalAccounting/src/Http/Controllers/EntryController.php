<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\AnalyticalAccounting\Http\Controllers\Concerns\InteractsWithAnalytical;
use Modules\AnalyticalAccounting\Http\Requests\EntryRequest;
use Modules\AnalyticalAccounting\Http\Requests\RunRequest;
use Modules\AnalyticalAccounting\Http\Resources\EntryResource;
use Modules\AnalyticalAccounting\Models\AnalyticalEntry;
use Modules\AnalyticalAccounting\Services\AnalyticalPostingService;

/**
 * Management ledger: query with drill-down filters, manual analytical adjustments with allocation vectors, and reversal.
 * The statutory general ledger is never written from here.
 */
final class EntryController extends Controller
{
    use InteractsWithAnalytical;

    public function __construct(private readonly AnalyticalPostingService $posting) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AnalyticalEntry::class);
        $query = AnalyticalEntry::query()->with('lines')->where('tenant_id', $this->tenantId())->orderByDesc('posting_date')->orderByDesc('created_at');
        foreach (['entry_type', 'period_key', 'source_type', 'source_id', 'apportionment_run_id', 'gl_journal_entry_id', 'invoice_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }
        foreach (['cost_center_id', 'profit_center_id', 'project_id', 'product_line_id'] as $dimension) {
            if ($request->filled($dimension)) {
                $query->whereHas('lines', fn ($q) => $q->where($dimension, $request->query($dimension)));
            }
        }

        return EntryResource::collection($query->paginate($this->perPage()));
    }

    public function show(string $id): EntryResource
    {
        $entry = $this->find($id);
        $this->authorize('view', $entry);

        return new EntryResource($entry->load('lines'));
    }

    public function store(EntryRequest $request): JsonResponse
    {
        $this->authorize('create', AnalyticalEntry::class);
        $data = $request->validated();
        $lines = array_map(static fn (array $line): array => $line, $data['lines']);
        unset($data['lines']);
        $entry = $this->domain(fn (): AnalyticalEntry => $this->posting->post($this->tenantId(), $data + ['entry_type' => 'MANUAL_ADJUSTMENT', 'source_type' => 'manual'], $lines, $this->userId()));

        return (new EntryResource($entry))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function reverse(RunRequest $request, string $id): JsonResponse
    {
        $entry = $this->find($id);
        $this->authorize('update', $entry);
        $reversal = $this->domain(fn (): AnalyticalEntry => $this->posting->reverse($entry, $this->userId(), $request->validated()['reason'] ?? null));

        return (new EntryResource($reversal->load('lines')))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    private function find(string $id): AnalyticalEntry
    {
        return AnalyticalEntry::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
    }
}

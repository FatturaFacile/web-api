<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\RecurringContracts;

use App\Http\Controllers\Controller;
use App\Http\Resources\RecurringContracts\RecurringBillingRunResource;
use App\Models\RecurringContracts\RecurringBillingRun;
use App\Services\RecurringContracts\PeriodicBillingBatchProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RecurringBillingRunController extends Controller
{
    public function __construct(
        protected PeriodicBillingBatchProcessor $batchProcessor
    ) {}

    /**
     * Preview contracts due for billing up to cutoff date.
     */
    public function preview(Request $request): JsonResponse
    {
        $cutoffDateStr = $request->query('cutoff_date');
        $cutoffDate = $cutoffDateStr ? CarbonImmutable::parse($cutoffDateStr) : CarbonImmutable::today();

        $preview = $this->batchProcessor->previewDueContracts($cutoffDate);

        return response()->json([
            'cutoff_date' => $cutoffDate->toDateString(),
            'total_due' => count($preview),
            'items' => $preview,
        ]);
    }

    /**
     * List billing runs.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $runs = RecurringBillingRun::with('items')
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 15));

        return RecurringBillingRunResource::collection($runs);
    }

    /**
     * Show billing run detail.
     */
    public function show(string $id): RecurringBillingRunResource
    {
        $run = RecurringBillingRun::with(['items.contract.customer', 'items.invoice'])->findOrFail($id);

        return new RecurringBillingRunResource($run);
    }

    /**
     * Execute a new manual billing run.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'cutoff_date' => ['nullable', 'date'],
            'contract_ids' => ['nullable', 'array'],
            'contract_ids.*' => ['uuid', 'exists:recurring_contracts,id'],
        ]);

        $cutoffDateStr = $request->input('cutoff_date');
        $cutoffDate = $cutoffDateStr ? CarbonImmutable::parse($cutoffDateStr) : CarbonImmutable::today();
        $contractIds = $request->input('contract_ids');

        $run = $this->batchProcessor->executeBatchRun($cutoffDate, 'manual', null, $contractIds);

        return (new RecurringBillingRunResource($run->load(['items.contract.customer', 'items.invoice'])))
            ->response()
            ->setStatusCode(201);
    }
}

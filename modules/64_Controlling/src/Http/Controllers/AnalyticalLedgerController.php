<?php

declare(strict_types=1);

namespace Modules\Controlling\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Modules\Controlling\Http\Controllers\Concerns\InteractsWithControlling;
use Modules\Controlling\Http\Requests\LedgerEntryRequest;
use Modules\Controlling\Http\Resources\LedgerEntryResource;
use Modules\Controlling\Models\AnalyticalLedgerEntry;

/**
 * Analytical journal: filterable list and manual analytical adjustments.
 */
final class AnalyticalLedgerController extends Controller
{
    use InteractsWithControlling;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AnalyticalLedgerEntry::class);
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date'], 'fiscal_year' => ['nullable', 'integer'], 'fiscal_month' => ['nullable', 'integer', 'between:1,12'],
            'cost_center_id' => ['nullable', 'uuid'], 'job_id' => ['nullable', 'uuid'], 'nature_type' => ['nullable', Rule::in(AnalyticalLedgerEntry::Natures)],
            'source_type' => ['nullable', Rule::in(AnalyticalLedgerEntry::SourceTypes)], 'allocation_run_id' => ['nullable', 'uuid'],
        ]);

        return LedgerEntryResource::collection(
            AnalyticalLedgerEntry::query()->where('tenant_id', $this->tenantId())
                ->when(isset($filters['date_from']), static fn ($q) => $q->whereDate('posting_date', '>=', $filters['date_from']))
                ->when(isset($filters['date_to']), static fn ($q) => $q->whereDate('posting_date', '<=', $filters['date_to']))
                ->when(isset($filters['fiscal_year']), static fn ($q) => $q->where('fiscal_year', $filters['fiscal_year']))
                ->when(isset($filters['fiscal_month']), static fn ($q) => $q->where('fiscal_month', $filters['fiscal_month']))
                ->when(isset($filters['cost_center_id']), static fn ($q) => $q->where('cost_center_id', $filters['cost_center_id']))
                ->when(isset($filters['job_id']), static fn ($q) => $q->where('job_id', $filters['job_id']))
                ->when(isset($filters['nature_type']), static fn ($q) => $q->where('nature_type', $filters['nature_type']))
                ->when(isset($filters['source_type']), static fn ($q) => $q->where('source_type', $filters['source_type']))
                ->when(isset($filters['allocation_run_id']), static fn ($q) => $q->where('allocation_run_id', $filters['allocation_run_id']))
                ->orderByDesc('posting_date')->orderByDesc('id')->paginate($this->perPage()),
        );
    }

    public function store(LedgerEntryRequest $request): JsonResponse
    {
        $this->authorize('create', AnalyticalLedgerEntry::class);
        $data = $request->validated();
        $entry = AnalyticalLedgerEntry::create($data + [
            'tenant_id' => $this->tenantId(), 'source_type' => 'manual_analytical', 'quantity' => 0,
            'fiscal_year' => (int) substr((string) $data['posting_date'], 0, 4), 'fiscal_month' => (int) substr((string) $data['posting_date'], 5, 2),
        ]);

        return (new LedgerEntryResource($entry))->response()->setStatusCode(Response::HTTP_CREATED);
    }
}

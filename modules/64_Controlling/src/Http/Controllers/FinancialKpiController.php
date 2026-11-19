<?php

declare(strict_types=1);

namespace Modules\Controlling\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Controlling\Http\Controllers\Concerns\InteractsWithControlling;
use Modules\Controlling\Http\Requests\SnapshotRequest;
use Modules\Controlling\Models\FinancialSnapshot;
use Modules\Controlling\Services\FinancialKpiService;

/**
 * Working capital KPIs (DSO, DPO, CCN, inventory turnover, DIO, CCC) with their monthly history.
 */
final class FinancialKpiController extends Controller
{
    use InteractsWithControlling;

    public function __construct(private readonly FinancialKpiService $kpis) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', FinancialSnapshot::class);
        $filters = $request->validate(['as_of' => ['nullable', 'date'], 'months' => ['nullable', 'integer', 'between:1,36']]);

        return response()->json(['data' => $this->kpis->kpis($this->tenantId(), $filters['as_of'] ?? null, (int) ($filters['months'] ?? 12))]);
    }

    /**
     * Store the snapshot of a date: computed from the receivables, payables, stock and bank, or entered manually.
     */
    public function snapshot(SnapshotRequest $request): JsonResponse
    {
        $this->authorize('create', FinancialSnapshot::class);
        $data = $request->validated();
        $date = CarbonImmutable::parse($data['snapshot_date']);
        $snapshot = ($data['mode'] ?? 'compute') === 'manual'
            ? $this->kpis->store($this->tenantId(), $date, array_diff_key($data, ['snapshot_date' => 1, 'mode' => 1]))
            : $this->kpis->buildSnapshot($this->tenantId(), $date);

        return response()->json(['data' => $snapshot->only(['id', 'snapshot_date', 'fiscal_year', 'fiscal_month', 'accounts_receivable_balance', 'accounts_payable_balance', 'inventory_valuation_balance', 'net_working_capital'])], Response::HTTP_CREATED);
    }
}

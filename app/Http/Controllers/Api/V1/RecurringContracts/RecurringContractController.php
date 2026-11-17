<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\RecurringContracts;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecurringContracts\StoreRecurringContractRequest;
use App\Http\Requests\RecurringContracts\UpdateRecurringContractRequest;
use App\Http\Resources\RecurringContracts\ContractIndexationLogResource;
use App\Http\Resources\RecurringContracts\RecurringContractResource;
use App\Models\RecurringContracts\PriceIndexRate;
use App\Models\RecurringContracts\RecurringContract;
use App\Services\RecurringContracts\IndexationAdjustmentEngine;
use App\Services\RecurringContracts\PeriodicBillingBatchProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RecurringContractController extends Controller
{
    public function __construct(
        protected PeriodicBillingBatchProcessor $batchProcessor,
        protected IndexationAdjustmentEngine $indexationEngine
    ) {}

    /**
     * Display a listing of recurring contracts.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = RecurringContract::with(['customer', 'lines', 'indexationClause']);

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('contract_type')) {
            $query->where('contract_type', $request->query('contract_type'));
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->query('customer_id'));
        }

        if ($request->filled('due_before')) {
            $query->where('next_billing_date', '<=', $request->query('due_before'));
        }

        if ($request->filled('renewing_within_days')) {
            $days = (int) $request->query('renewing_within_days');
            $query->whereNotNull('end_date')
                ->whereBetween('end_date', [now()->toDateString(), now()->addDays($days)->toDateString()]);
        }

        if ($request->filled('search')) {
            $search = '%'.$request->query('search').'%';
            $query->where(function ($q) use ($search) {
                $q->where('contract_number', 'like', $search)
                    ->orWhere('title', 'like', $search)
                    ->orWhereHas('customer', fn ($sq) => $sq->where('company_name', 'like', $search));
            });
        }

        $contracts = $query->orderByDesc('created_at')->paginate($request->integer('per_page', 15));

        return RecurringContractResource::collection($contracts);
    }

    /**
     * Store a newly created recurring contract.
     */
    public function store(StoreRecurringContractRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $contract = DB::transaction(function () use ($validated, $request) {
            $tenantId = auth()->user()?->current_tenant_id ? (string) auth()->user()->current_tenant_id : (string) $request->header('X-Tenant-Id');

            $contractNumber = $validated['contract_number'] ?? ('CTR-'.strtoupper(Str::random(8)));
            $nextBillingDate = $validated['next_billing_date'] ?? $validated['start_date'];

            $contractData = collect($validated)->except(['lines', 'indexation_clause'])->toArray();
            $contractData['contract_number'] = $contractNumber;
            $contractData['next_billing_date'] = $nextBillingDate;
            $contractData['tenant_id'] = $tenantId;
            $contractData['status'] = $contractData['status'] ?? 'draft';

            $contract = RecurringContract::create($contractData);

            if (! empty($validated['lines'])) {
                foreach ($validated['lines'] as $index => $line) {
                    $line['sort_order'] = $line['sort_order'] ?? $index;
                    $contract->lines()->create($line);
                }
            }

            if (! empty($validated['indexation_clause'])) {
                $clause = $validated['indexation_clause'];
                $contract->indexationClauses()->create($clause);
            }

            return $contract->load(['customer', 'lines', 'indexationClause']);
        });

        return (new RecurringContractResource($contract))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified contract.
     */
    public function show(string $id): RecurringContractResource
    {
        $contract = RecurringContract::with(['customer', 'lines.product', 'lines.vatRate', 'indexationClause', 'indexationLogs', 'billingRunItems.invoice'])
            ->findOrFail($id);

        return new RecurringContractResource($contract);
    }

    /**
     * Update the specified contract.
     */
    public function update(UpdateRecurringContractRequest $request, string $id): RecurringContractResource
    {
        $contract = RecurringContract::findOrFail($id);
        $validated = $request->validated();

        DB::transaction(function () use ($contract, $validated) {
            $contractData = collect($validated)->except(['lines', 'indexation_clause'])->toArray();
            $contract->update($contractData);

            if (isset($validated['lines'])) {
                $contract->lines()->delete();
                foreach ($validated['lines'] as $index => $line) {
                    unset($line['id']);
                    $line['sort_order'] = $line['sort_order'] ?? $index;
                    $contract->lines()->create($line);
                }
            }

            if (isset($validated['indexation_clause'])) {
                $clauseData = $validated['indexation_clause'];
                if ($contract->indexationClause) {
                    $contract->indexationClause->update($clauseData);
                } else {
                    $contract->indexationClauses()->create($clauseData);
                }
            }
        });

        return new RecurringContractResource($contract->load(['customer', 'lines', 'indexationClause', 'indexationLogs']));
    }

    /**
     * Remove the specified contract.
     */
    public function destroy(string $id): JsonResponse
    {
        $contract = RecurringContract::findOrFail($id);
        $contract->delete();

        return response()->json(['message' => 'Recurring contract deleted successfully.']);
    }

    /**
     * Activate contract.
     */
    public function activate(string $id): RecurringContractResource
    {
        $contract = RecurringContract::findOrFail($id);
        $contract->update(['status' => 'active']);

        return new RecurringContractResource($contract->load(['customer', 'lines', 'indexationClause']));
    }

    /**
     * Suspend contract.
     */
    public function suspend(Request $request, string $id): RecurringContractResource
    {
        $contract = RecurringContract::findOrFail($id);
        $contract->update(['status' => 'suspended']);

        return new RecurringContractResource($contract->load(['customer', 'lines', 'indexationClause']));
    }

    /**
     * Cancel contract.
     */
    public function cancel(Request $request, string $id): RecurringContractResource
    {
        $request->validate([
            'cancellation_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $contract = RecurringContract::findOrFail($id);
        $contract->update([
            'status' => 'cancelled',
            'cancellation_reason' => $request->input('cancellation_reason'),
            'cancelled_at' => now(),
        ]);

        return new RecurringContractResource($contract->load(['customer', 'lines', 'indexationClause']));
    }

    /**
     * Renew contract manually.
     */
    public function renew(Request $request, string $id): RecurringContractResource
    {
        $request->validate([
            'renewal_months' => ['nullable', 'integer', 'min:1'],
        ]);

        $contract = RecurringContract::findOrFail($id);
        $months = $request->input('renewal_months') ?? ($contract->renewal_period_months ?? 12);

        $currentEnd = $contract->end_date ? CarbonImmutable::parse($contract->end_date) : CarbonImmutable::today();
        $newEnd = $currentEnd->addMonthsNoOverflow($months);

        $contract->update([
            'end_date' => $newEnd->toDateString(),
            'status' => 'active',
        ]);

        return new RecurringContractResource($contract->load(['customer', 'lines', 'indexationClause']));
    }

    /**
     * Apply indexation adjustment to contract.
     */
    public function applyIndexation(Request $request, string $id): ContractIndexationLogResource
    {
        $request->validate([
            'price_index_rate_id' => ['required', 'uuid', 'exists:price_index_rates,id'],
        ]);

        $contract = RecurringContract::with(['lines', 'indexationClause'])->findOrFail($id);
        $rate = PriceIndexRate::findOrFail($request->input('price_index_rate_id'));

        $log = $this->indexationEngine->applyIndexation($contract, $rate, auth()->id());

        return new ContractIndexationLogResource($log);
    }

    /**
     * Bill contract immediately.
     */
    public function billNow(string $id): JsonResponse
    {
        $contract = RecurringContract::with(['customer', 'lines', 'indexationClause'])->findOrFail($id);
        $invoice = $this->batchProcessor->billContractNow($contract);

        return response()->json([
            'message' => 'Invoice generated successfully for recurring contract.',
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'total_gross' => (string) $invoice->total_gross,
        ]);
    }

    /**
     * Return recurring contracts metrics (MRR, ARR, active count, expiring soon, due count).
     */
    public function metrics(Request $request): JsonResponse
    {
        $activeContracts = RecurringContract::with('lines')->where('status', 'active')->get();

        $activeCount = $activeContracts->count();
        $draftCount = RecurringContract::where('status', 'draft')->count();
        $suspendedCount = RecurringContract::where('status', 'suspended')->count();
        $dueCount = RecurringContract::where('status', 'active')
            ->where('next_billing_date', '<=', now()->toDateString())
            ->count();

        $expiringCount = RecurringContract::where('status', 'active')
            ->whereNotNull('end_date')
            ->whereBetween('end_date', [now()->toDateString(), now()->addDays(30)->toDateString()])
            ->count();

        $mrrChf = '0.0000';
        $mrrEur = '0.0000';

        foreach ($activeContracts as $contract) {
            $cycleTotal = $contract->calculateSubtotal();
            $monthlyFactor = match ($contract->billing_frequency) {
                'monthly' => '1.0000',
                'quarterly' => bcdiv('1', '3', 6),
                'semi_annual' => bcdiv('1', '6', 6),
                'annual' => bcdiv('1', '12', 6),
                'custom_days' => bcdiv('30', (string) max(1, $contract->custom_interval_days ?? 30), 6),
                default => '1.0000',
            };

            $monthlyAmount = bcmul($cycleTotal, $monthlyFactor, 4);

            if ($contract->currency === 'CHF') {
                $mrrChf = bcadd($mrrChf, $monthlyAmount, 4);
            } else {
                $mrrEur = bcadd($mrrEur, $monthlyAmount, 4);
            }
        }

        $arrChf = bcmul($mrrChf, '12', 4);
        $arrEur = bcmul($mrrEur, '12', 4);

        return response()->json([
            'active_count' => $activeCount,
            'draft_count' => $draftCount,
            'suspended_count' => $suspendedCount,
            'due_for_billing_count' => $dueCount,
            'expiring_in_30_days_count' => $expiringCount,
            'mrr' => [
                'CHF' => $mrrChf,
                'EUR' => $mrrEur,
            ],
            'arr' => [
                'CHF' => $arrChf,
                'EUR' => $arrEur,
            ],
        ]);
    }
}

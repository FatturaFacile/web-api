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

final class LoanerController extends Controller
{
    public function __construct(
        private readonly LoanerService $loanerService,
    ) {}

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

    public function store(StoreLoanerRequest $request, DepotRepairRmaOrder $rmaOrder): JsonResponse
    {
        $this->authorize('issueLoaner', $rmaOrder);

        $loaner = $this->loanerService->issue($rmaOrder, IssueLoanerDTO::fromValidated($request->validated()), $request->user()?->id);

        return response()->json([
            'message' => 'Loaner register entry created successfully',
            'data' => new LoanerResource($loaner),
        ], 201);
    }

    public function markReturned(ReturnLoanerRequest $request, Loaner $loaner): JsonResponse
    {
        $this->authorize('update', $loaner);

        $updated = $this->loanerService->markReturned($loaner, $request->validated('returned_at'));

        return response()->json([
            'message' => 'Loaner returned successfully',
            'data' => new LoanerResource($updated),
        ]);
    }

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

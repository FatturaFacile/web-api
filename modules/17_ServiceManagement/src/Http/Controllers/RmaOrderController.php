<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\ServiceManagement\Actions\LookupSerialWarrantyAndIssueRmaAction;
use Modules\ServiceManagement\Actions\ProcessBenchDiagnosisAndRepairQuoteAction;
use Modules\ServiceManagement\Contracts\RmaServiceInterface;
use Modules\ServiceManagement\DTOs\BenchDiagnosisDTO;
use Modules\ServiceManagement\DTOs\CompleteRmaRepairDTO;
use Modules\ServiceManagement\DTOs\IssueRmaDTO;
use Modules\ServiceManagement\DTOs\ReceiveRmaDTO;
use Modules\ServiceManagement\DTOs\ReturnRmaDTO;
use Modules\ServiceManagement\Http\Requests\CancelRmaRequest;
use Modules\ServiceManagement\Http\Requests\CompleteRmaRepairRequest;
use Modules\ServiceManagement\Http\Requests\ReceiveRmaRequest;
use Modules\ServiceManagement\Http\Requests\ReturnRmaRequest;
use Modules\ServiceManagement\Http\Requests\RmaDiagnosisRequest;
use Modules\ServiceManagement\Http\Requests\RmaEstimateDecisionRequest;
use Modules\ServiceManagement\Http\Requests\StoreRmaOrderRequest;
use Modules\ServiceManagement\Http\Resources\RmaOrderResource;
use Modules\ServiceManagement\Models\DepotRepairRmaOrder;
use Modules\ServiceManagement\Services\RmaService;

final class RmaOrderController extends Controller
{
    public function __construct(
        private readonly RmaServiceInterface $rmaService,
        private readonly LookupSerialWarrantyAndIssueRmaAction $issueAction,
        private readonly ProcessBenchDiagnosisAndRepairQuoteAction $diagnosisAction,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', DepotRepairRmaOrder::class);

        $rmas = DepotRepairRmaOrder::query()
            ->with(['passport', 'customer', 'lines', 'history', 'vendorClaims.supplier', 'loaner.rmaOrder'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->query('status')))
            ->when($request->filled('warranty_status'), fn ($query) => $query->where('warranty_status', $request->query('warranty_status')))
            ->when($request->filled('customer_id'), fn ($query) => $query->where('customer_id', $request->query('customer_id')))
            ->when($request->filled('serial_number'), fn ($query) => $query->whereHas(
                'passport',
                fn ($passport) => $passport->where('serial_number', (string) $request->query('serial_number')),
            ))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.trim((string) $request->query('search')).'%';
                $query->where(function ($inner) use ($term): void {
                    $inner->where('rma_number', 'like', $term)
                        ->orWhere('reported_symptom', 'like', $term)
                        ->orWhereHas('passport', fn ($passport) => $passport->where('serial_number', 'like', $term));
                });
            })
            ->orderByDesc('created_at')
            ->paginate(25);

        return response()->json([
            'data' => RmaOrderResource::collection($rmas->getCollection()),
            'meta' => [
                'current_page' => $rmas->currentPage(),
                'last_page' => $rmas->lastPage(),
                'per_page' => $rmas->perPage(),
                'total' => $rmas->total(),
            ],
        ]);
    }

    public function store(StoreRmaOrderRequest $request): JsonResponse
    {
        $this->authorize('create', DepotRepairRmaOrder::class);
        if ($request->boolean('goodwill')) {
            Gate::authorize('grantGoodwill', DepotRepairRmaOrder::class);
        }

        $tenant = $this->tenant();
        $result = $this->issueAction->execute(
            IssueRmaDTO::fromValidated($tenant->id, $tenant->id, $request->validated()),
            $request->user()?->id,
        );

        return response()->json([
            'message' => 'RMA issued successfully',
            'data' => array_merge(
                (new RmaOrderResource($result['rma']))->resolve($request),
                ['label' => $result['label']],
            ),
        ], 201);
    }

    public function show(DepotRepairRmaOrder $rmaOrder): JsonResponse
    {
        $this->authorize('view', $rmaOrder);

        return response()->json(['data' => new RmaOrderResource($rmaOrder)]);
    }

    public function label(DepotRepairRmaOrder $rmaOrder): JsonResponse
    {
        $this->authorize('view', $rmaOrder);

        return response()->json(['data' => $this->rmaService->label($rmaOrder)]);
    }

    public function regenerateTrackingToken(Request $request, DepotRepairRmaOrder $rmaOrder): JsonResponse
    {
        $this->authorize('update', $rmaOrder);

        $raw = $this->rmaService->regenerateTrackingToken($rmaOrder, $request->user()?->id);

        return response()->json([
            'message' => 'Tracking link regenerated successfully',
            'data' => $this->rmaService->label($rmaOrder, $raw),
        ]);
    }

    public function receive(ReceiveRmaRequest $request, DepotRepairRmaOrder $rmaOrder): JsonResponse
    {
        $this->authorize('transition', $rmaOrder);

        $rma = $this->rmaService->receive($rmaOrder, ReceiveRmaDTO::fromValidated($request->validated()), $request->user()?->id);

        return $this->respond('RMA unit received', $rma);
    }

    public function diagnosis(RmaDiagnosisRequest $request, DepotRepairRmaOrder $rmaOrder): JsonResponse
    {
        $this->authorize('transition', $rmaOrder);
        if ($request->boolean('goodwill')) {
            Gate::authorize('grantGoodwill', DepotRepairRmaOrder::class);
        }

        $result = $this->diagnosisAction->execute($rmaOrder, BenchDiagnosisDTO::fromValidated($request->validated()), $request->user()?->id);

        $extra = $result['estimate_token'] !== null
            ? ['estimate_url' => $result['estimate_url'], 'estimate_token' => $result['estimate_token']]
            : [];

        return $this->respond('Bench diagnosis recorded', $result['rma'], $extra, $request);
    }

    public function approveEstimate(RmaEstimateDecisionRequest $request, DepotRepairRmaOrder $rmaOrder): JsonResponse
    {
        $this->authorize('transition', $rmaOrder);

        $rma = $this->rmaService->approveEstimate(
            $rmaOrder,
            RmaService::DECIDED_VIA_STAFF,
            $request->user()?->id,
            $request->user()?->name,
            $request->ip(),
            $request->validated('note'),
        );

        return $this->respond('Estimate approved', $rma);
    }

    public function declineEstimate(RmaEstimateDecisionRequest $request, DepotRepairRmaOrder $rmaOrder): JsonResponse
    {
        $this->authorize('transition', $rmaOrder);

        $rma = $this->rmaService->declineEstimate(
            $rmaOrder,
            RmaService::DECIDED_VIA_STAFF,
            $request->user()?->id,
            $request->user()?->name,
            $request->ip(),
            $request->validated('note'),
        );

        return $this->respond('Estimate declined', $rma);
    }

    public function resendEstimate(Request $request, DepotRepairRmaOrder $rmaOrder): JsonResponse
    {
        $this->authorize('transition', $rmaOrder);

        $result = $this->rmaService->resendEstimate($rmaOrder, $request->user()?->id);

        return $this->respond('Estimate link re-issued', $result['rma'], [
            'estimate_url' => $this->rmaService->estimateUrl($result['estimate_token']),
            'estimate_token' => $result['estimate_token'],
        ], $request);
    }

    public function partsReceived(Request $request, DepotRepairRmaOrder $rmaOrder): JsonResponse
    {
        $this->authorize('transition', $rmaOrder);

        return $this->respond('Spare parts received', $this->rmaService->markPartsReceived($rmaOrder, $request->user()?->id));
    }

    public function completeRepair(CompleteRmaRepairRequest $request, DepotRepairRmaOrder $rmaOrder): JsonResponse
    {
        $this->authorize('transition', $rmaOrder);

        $rma = $this->rmaService->completeRepair($rmaOrder, CompleteRmaRepairDTO::fromValidated($request->validated()), $request->user()?->id);

        return $this->respond('Repair completed', $rma);
    }

    public function returnToCustomer(ReturnRmaRequest $request, DepotRepairRmaOrder $rmaOrder): JsonResponse
    {
        $this->authorize('transition', $rmaOrder);

        $rma = $this->rmaService->returnToCustomer($rmaOrder, ReturnRmaDTO::fromValidated($request->validated()), $request->user()?->id);

        return $this->respond('Unit returned to customer', $rma);
    }

    public function close(Request $request, DepotRepairRmaOrder $rmaOrder): JsonResponse
    {
        $this->authorize('transition', $rmaOrder);

        return $this->respond('RMA closed', $this->rmaService->close($rmaOrder, $request->user()?->id));
    }

    public function cancel(CancelRmaRequest $request, DepotRepairRmaOrder $rmaOrder): JsonResponse
    {
        $this->authorize('transition', $rmaOrder);

        $rma = $this->rmaService->cancel($rmaOrder, (string) $request->validated('reason'), $request->user()?->id);

        return $this->respond('RMA cancelled', $rma);
    }

    /**
     * @param  array<string, mixed>  $extra  one-time fields (raw tokens) merged into `data`
     */
    private function respond(string $message, DepotRepairRmaOrder $rma, array $extra = [], ?Request $request = null): JsonResponse
    {
        $resource = new RmaOrderResource($rma);

        return response()->json([
            'message' => $message,
            'data' => $extra === [] ? $resource : array_merge($resource->resolve($request ?? request()), $extra),
        ]);
    }

    private function tenant(): Tenant
    {
        $tenant = $this->tenantContext->getTenant();
        abort_if($tenant === null, 403, 'No active tenant.');

        return $tenant;
    }
}

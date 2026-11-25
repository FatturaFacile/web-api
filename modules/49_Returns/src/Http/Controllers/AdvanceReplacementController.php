<?php

declare(strict_types=1);

namespace Modules\Returns\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Returns\Http\Controllers\Concerns\InteractsWithReturns;
use Modules\Returns\Http\Requests\AdvanceReplacementBillRequest;
use Modules\Returns\Http\Requests\AdvanceReplacementReceiveRequest;
use Modules\Returns\Http\Requests\AdvanceReplacementStoreRequest;
use Modules\Returns\Http\Resources\AdvanceReplacementResource;
use Modules\Returns\Models\AdvanceReplacement;
use Modules\Returns\Services\AdvanceReplacementService;
use OpenApi\Attributes as OA;

/**
 * Advance Replacement / Cross-Shipping endpoints (Mod 232).
 */
final class AdvanceReplacementController extends Controller
{
    use InteractsWithReturns;

    public function __construct(
        private readonly AdvanceReplacementService $replacements,
    ) {}

    #[OA\Get(path: '/api/v1/returns/advance-replacements', operationId: 'listAdvanceReplacements', summary: 'List advance replacement (cross-shipping) orders', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Returns'], responses: [
        new OA\Response(response: 200, description: 'Advance replacements'),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AdvanceReplacement::class);
        $status = $request->query('status');

        return AdvanceReplacementResource::collection(
            AdvanceReplacement::query()
                ->with(['contact', 'customerReturn', 'faultyProduct', 'replacementProduct'])
                ->where('tenant_id', $this->tenantId())
                ->when(is_string($status) && $status !== '', static fn ($q) => $q->where('status', $status))
                ->orderByDesc('shipped_on')
                ->orderByDesc('replacement_number')
                ->paginate($this->perPage())
        );
    }

    #[OA\Post(path: '/api/v1/returns/advance-replacements', operationId: 'storeAdvanceReplacement', summary: 'Create an advance replacement (cross-shipping) order', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Returns'], responses: [
        new OA\Response(response: 201, description: 'Advance replacement created'),
    ])]
    public function store(AdvanceReplacementStoreRequest $request): JsonResponse
    {
        $this->authorize('create', AdvanceReplacement::class);
        $record = $this->replacements->create($this->tenantId(), $request->validated(), $this->userId());

        return (new AdvanceReplacementResource($record))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Post(path: '/api/v1/returns/advance-replacements/{id}/receive-defective', operationId: 'receiveDefectiveAdvanceReplacement', summary: 'Mark defective unit as received for an advance replacement', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Returns'], responses: [
        new OA\Response(response: 200, description: 'Defective received'),
    ])]
    public function receiveDefective(AdvanceReplacementReceiveRequest $request, string $id): AdvanceReplacementResource
    {
        $record = AdvanceReplacement::query()->findOrFail($id);
        $this->authorize('update', $record);

        return new AdvanceReplacementResource(
            $this->replacements->receiveDefective($record, $request->validated()['notes'] ?? null)
        );
    }

    #[OA\Post(path: '/api/v1/returns/advance-replacements/{id}/bill-overdue', operationId: 'billOverdueAdvanceReplacement', summary: 'Issue penalty invoice for an overdue advance replacement', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Returns'], responses: [
        new OA\Response(response: 200, description: 'Overdue penalty billed'),
    ])]
    public function billOverdue(AdvanceReplacementBillRequest $request, string $id): AdvanceReplacementResource
    {
        $record = AdvanceReplacement::query()->findOrFail($id);
        $this->authorize('approve', $record);

        return new AdvanceReplacementResource(
            $this->replacements->billOverdue($record, $this->userId(), (bool) ($request->validated()['force'] ?? false))
        );
    }

    #[OA\Post(path: '/api/v1/returns/advance-replacements/scan-overdue', operationId: 'scanOverdueAdvanceReplacements', summary: 'Scan and bill all overdue advance replacements for the active tenant', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Returns'], responses: [
        new OA\Response(response: 200, description: 'Overdue scan completed'),
    ])]
    public function scanOverdue(): JsonResponse
    {
        $this->authorize('create', AdvanceReplacement::class);
        $billed = $this->replacements->processOverdueForTenant($this->tenantId(), $this->userId());

        return response()->json(['data' => ['billed_count' => $billed]]);
    }
}

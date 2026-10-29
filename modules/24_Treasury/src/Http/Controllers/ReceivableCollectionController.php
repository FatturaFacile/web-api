<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Treasury\Contracts\ReceivableCollectionServiceInterface;
use Modules\Treasury\Http\Resources\ReceivableCollectionResource;
use Modules\Treasury\Models\ReceivableCollection;

final class ReceivableCollectionController extends Controller
{
    public function __construct(
        private readonly ReceivableCollectionServiceInterface $collectionService
    ) {}

    public function index(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        $query = ReceivableCollection::where('tenant_id', $tenantId);

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->query('customer_id'));
        }

        $collections = $query->latest('due_date')->paginate((int) $request->query('per_page', 25));

        return response()->json(ReceivableCollectionResource::collection($collections)->response()->getData(true));
    }

    public function aging(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        $companyId = $request->query('company_id');

        $aging = $this->collectionService->getCustomerAging($tenantId, $companyId);

        return response()->json($aging);
    }

    public function recordCollection(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'collection_date' => ['nullable', 'date'],
        ]);

        $date = (string) $request->input('collection_date', now()->toDateString());
        $collection = $this->collectionService->recordCollectionReceived($id, (float) $request->input('amount'), $date);

        return response()->json(new ReceivableCollectionResource($collection));
    }

    public function evaluateDunning(TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        $dunningCandidates = $this->collectionService->evaluateDunningReadiness($tenantId);

        return response()->json([
            'evaluated_count' => count($dunningCandidates),
            'overdue_items' => ReceivableCollectionResource::collection($dunningCandidates),
        ]);
    }
}

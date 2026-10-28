<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Procurement\Http\Resources\SupplierContractResource;
use Modules\Procurement\Models\SupplierContract;

final class SupplierContractController extends Controller
{
    #[EnterpriseEndpoint(summary: 'List supplier framework agreements and master contracts', tags: ['Procurement'])]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SupplierContract::class);

        $query = SupplierContract::with('supplier')
            ->orderBy('ends_at', 'asc');

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->input('supplier_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $contracts = $query->paginate((int) $request->query('per_page', 25));

        return response()->json(SupplierContractResource::collection($contracts)->response()->getData(true));
    }

    #[EnterpriseEndpoint(summary: 'Show supplier contract details', tags: ['Procurement'])]
    public function show(string $id): JsonResponse
    {
        $contract = SupplierContract::with(['supplier', 'purchaseOrders'])->findOrFail($id);
        $this->authorize('view', $contract);

        return response()->json(new SupplierContractResource($contract));
    }
}

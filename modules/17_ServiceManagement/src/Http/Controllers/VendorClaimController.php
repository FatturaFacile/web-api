<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\ServiceManagement\Enums\VendorClaimStatus;
use Modules\ServiceManagement\Http\Requests\TransitionVendorClaimRequest;
use Modules\ServiceManagement\Http\Requests\UpdateVendorClaimRequest;
use Modules\ServiceManagement\Http\Resources\VendorClaimResource;
use Modules\ServiceManagement\Models\VendorWarrantyClaim;
use Modules\ServiceManagement\Services\VendorClaimService;

final class VendorClaimController extends Controller
{
    public function __construct(
        private readonly VendorClaimService $claimService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', VendorWarrantyClaim::class);

        $claims = VendorWarrantyClaim::query()
            ->with(['rmaOrder', 'supplier'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->query('status')))
            ->when($request->filled('supplier_id'), fn ($query) => $query->where('original_supplier_id', $request->query('supplier_id')))
            ->when($request->filled('rma_order_id'), fn ($query) => $query->where('rma_order_id', $request->query('rma_order_id')))
            ->orderByDesc('created_at')
            ->paginate(25);

        return response()->json([
            'data' => VendorClaimResource::collection($claims->getCollection()),
            'meta' => [
                'current_page' => $claims->currentPage(),
                'last_page' => $claims->lastPage(),
                'per_page' => $claims->perPage(),
                'total' => $claims->total(),
            ],
        ]);
    }

    public function show(VendorWarrantyClaim $vendorClaim): JsonResponse
    {
        $this->authorize('view', $vendorClaim);

        return response()->json(['data' => new VendorClaimResource($vendorClaim->load(['rmaOrder', 'supplier']))]);
    }

    public function update(UpdateVendorClaimRequest $request, VendorWarrantyClaim $vendorClaim): JsonResponse
    {
        $this->authorize('update', $vendorClaim);

        $updated = $this->claimService->update($vendorClaim, $request->validated());

        return response()->json([
            'message' => 'Vendor claim updated successfully',
            'data' => new VendorClaimResource($updated),
        ]);
    }

    public function transition(TransitionVendorClaimRequest $request, VendorWarrantyClaim $vendorClaim): JsonResponse
    {
        $this->authorize('transition', $vendorClaim);

        $updated = $this->claimService->transition(
            $vendorClaim,
            VendorClaimStatus::from((string) $request->validated('to')),
            $request->validated('credit_note_reference'),
            $request->validated('note'),
        );

        return response()->json([
            'message' => 'Vendor claim moved to '.$updated->status,
            'data' => new VendorClaimResource($updated),
        ]);
    }
}

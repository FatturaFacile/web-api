<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Manufacturing\Contracts\BillOfMaterialsServiceInterface;
use Modules\Manufacturing\DTOs\CreateBomDTO;
use Modules\Manufacturing\Http\Controllers\Concerns\ResolvesTenant;
use Modules\Manufacturing\Http\Requests\CreateBomRequest;
use Modules\Manufacturing\Http\Requests\ReviseBomRequest;
use Modules\Manufacturing\Http\Resources\MfgBomResource;
use Modules\Manufacturing\Models\MfgBom;

final class BomController extends Controller
{
    use ResolvesTenant;

    public function __construct(
        private BillOfMaterialsServiceInterface $bomService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $boms = MfgBom::where('tenant_id', $tenantId)->latest()->paginate(25);

        return response()->json([
            'data' => MfgBomResource::collection($boms),
            'meta' => [
                'current_page' => $boms->currentPage(),
                'total' => $boms->total(),
            ],
        ]);
    }

    public function store(CreateBomRequest $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $validated = $request->validated();

        $dto = new CreateBomDTO(
            tenantId: $tenantId,
            mfgItemId: $validated['mfg_item_id'],
            name: $validated['name'],
            revision: $validated['revision'] ?? 'A',
            bomType: $validated['bom_type'] ?? 'standard',
            baseQuantity: (float) ($validated['base_quantity'] ?? 1.0),
            uom: $validated['uom'] ?? 'PCS',
            effectiveFrom: $validated['effective_from'] ?? null,
            effectiveTo: $validated['effective_to'] ?? null,
            components: $validated['components']
        );

        $bom = $this->bomService->createBom($dto);

        return response()->json([
            'message' => 'Bill of Materials created successfully.',
            'data' => new MfgBomResource($bom),
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $bom = MfgBom::where('tenant_id', $tenantId)->findOrFail($id);

        return response()->json(['data' => new MfgBomResource($bom)]);
    }

    public function explode(Request $request, string $id): JsonResponse
    {
        $units = (float) $request->query('quantity', 1.0);
        $tree = $this->bomService->explodeBom($id, $units);

        return response()->json([
            'bom_id' => $id,
            'quantity' => $units,
            'exploded_components' => $tree,
        ]);
    }

    public function revise(ReviseBomRequest $request, string $id): JsonResponse
    {
        $validated = $request->validated();
        $bom = $this->bomService->reviseBom($id, $validated['new_revision'], $validated['reason']);

        return response()->json([
            'message' => 'BOM revised successfully.',
            'data' => new MfgBomResource($bom),
        ]);
    }
}

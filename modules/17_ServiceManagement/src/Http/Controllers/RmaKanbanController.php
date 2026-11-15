<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\ServiceManagement\Contracts\RmaServiceInterface;
use Modules\ServiceManagement\Models\DepotRepairRmaOrder;

/**
 * Depot repair Kanban board: one column per RMA status (cancelled excluded).
 */
final class RmaKanbanController extends Controller
{
    public function __construct(
        private readonly RmaServiceInterface $rmaService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('viewAny', DepotRepairRmaOrder::class);

        $columns = $this->rmaService->board([
            'warranty_status' => $request->filled('warranty_status') ? (string) $request->query('warranty_status') : null,
            'customer_id' => $request->filled('customer_id') ? (string) $request->query('customer_id') : null,
            'include_closed' => $request->boolean('include_closed'),
        ]);

        return response()->json([
            'data' => ['columns' => $columns],
            'meta' => ['generated_at' => now()->toIso8601String()],
        ]);
    }
}

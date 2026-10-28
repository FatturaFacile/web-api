<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Procurement\Actions\ExecuteThreeWayMatchAction;
use Modules\Procurement\DTOs\ExecuteThreeWayMatchDTO;
use Modules\Procurement\Http\Requests\ExecuteThreeWayMatchRequest;
use Modules\Procurement\Http\Resources\ThreeWayMatchLogResource;
use Modules\Procurement\Models\ThreeWayMatchLog;

final class ThreeWayMatchController extends Controller
{
    #[EnterpriseEndpoint(summary: 'List three-way matching audit logs and variance reports', tags: ['Procurement'])]
    public function index(Request $request): JsonResponse
    {
        $query = ThreeWayMatchLog::with(['purchaseOrder', 'receipt'])
            ->orderBy('evaluated_at', 'desc');

        if ($request->filled('match_status')) {
            $query->where('match_status', $request->input('match_status'));
        }

        $logs = $query->paginate((int) $request->query('per_page', 25));

        return response()->json(ThreeWayMatchLogResource::collection($logs)->response()->getData(true));
    }

    #[EnterpriseEndpoint(summary: 'Execute algorithmic three-way match on PO, GRN and Inbound Invoice', tags: ['Procurement'])]
    public function execute(ExecuteThreeWayMatchRequest $request, ExecuteThreeWayMatchAction $action): JsonResponse
    {
        $dto = new ExecuteThreeWayMatchDTO(
            purchaseOrderId: $request->string('purchase_order_id')->value(),
            purchaseReceiptId: $request->string('purchase_receipt_id')->value(),
            passiveInvoiceId: $request->string('passive_invoice_id')->value(),
            invoiceTotalAmount: (float) $request->input('invoice_total_amount'),
            approvedByUserId: (string) auth()->id()
        );

        $log = $action->execute($dto);

        return response()->json(new ThreeWayMatchLogResource($log), 201);
    }
}

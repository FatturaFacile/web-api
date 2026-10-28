<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Procurement\Actions\ProcessPurchaseReturnAction;
use Modules\Procurement\DTOs\ProcessPurchaseReturnDTO;
use Modules\Procurement\Http\Requests\ProcessPurchaseReturnRequest;
use Modules\Procurement\Http\Resources\PurchaseReturnResource;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseReturn;

final class PurchaseReturnController extends Controller
{
    #[EnterpriseEndpoint(summary: 'List purchase returns', tags: ['Procurement'])]
    public function index(Request $request): JsonResponse
    {
        $returns = PurchaseReturn::with(['purchaseOrder', 'supplier', 'returner', 'lines'])
            ->orderBy('return_date', 'desc')
            ->paginate((int) $request->query('per_page', 25));

        return response()->json(PurchaseReturnResource::collection($returns)->response()->getData(true));
    }

    #[EnterpriseEndpoint(summary: 'Create purchase return to vendor', tags: ['Procurement'])]
    public function store(string $orderId, ProcessPurchaseReturnRequest $request, ProcessPurchaseReturnAction $action): JsonResponse
    {
        $order = PurchaseOrder::findOrFail($orderId);

        $dto = new ProcessPurchaseReturnDTO(
            purchaseOrderId: $order->id,
            supplierId: $request->string('supplier_id')->value(),
            returnDate: $request->string('return_date')->value(),
            returnedByUserId: (string) auth()->id(),
            lines: (array) $request->input('lines'),
            purchaseReceiptId: $request->input('purchase_receipt_id'),
            returnNumber: $request->input('return_number'),
            reason: $request->input('reason'),
            notes: $request->input('notes')
        );

        $return = $action->execute($dto);

        return response()->json(new PurchaseReturnResource($return), 201);
    }
}

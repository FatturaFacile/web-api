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
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Procurement Returns', description: 'Vendor return authorization (RTV) and defective material management')]
final class PurchaseReturnController extends Controller
{
    #[OA\Get(
        path: '/api/v1/procurement/returns',
        summary: 'List purchase returns',
        description: 'Retrieve paginated vendor return authorizations.',
        tags: ['Procurement Returns'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', description: 'Page number', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', description: 'Items per page', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated returns list'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'List purchase returns', tags: ['Procurement'])]
    public function index(Request $request): JsonResponse
    {
        $returns = PurchaseReturn::with(['purchaseOrder', 'supplier', 'returner', 'lines'])
            ->orderBy('return_date', 'desc')
            ->paginate((int) $request->query('per_page', 25));

        return response()->json(PurchaseReturnResource::collection($returns)->response()->getData(true));
    }

    #[OA\Post(
        path: '/api/v1/procurement/purchase-orders/{id}/returns',
        summary: 'Create purchase return to vendor',
        description: 'Records items returned to vendor with return reason and updates inventory.',
        tags: ['Procurement Returns'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'Purchase Order UUID', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent),
        responses: [
            new OA\Response(response: 201, description: 'Purchase return created'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
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

    #[OA\Get(
        path: '/api/v1/procurement/returns/{id}',
        summary: 'Show purchase return details',
        description: 'Retrieve return details, returned quantities, and reason.',
        tags: ['Procurement Returns'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'Return UUID', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Return details'),
            new OA\Response(response: 404, description: 'Return not found'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Show purchase return details', tags: ['Procurement'])]
    public function show(string $id): JsonResponse
    {
        $return = PurchaseReturn::with(['purchaseOrder', 'supplier', 'returner', 'lines'])->findOrFail($id);

        return response()->json(new PurchaseReturnResource($return));
    }
}

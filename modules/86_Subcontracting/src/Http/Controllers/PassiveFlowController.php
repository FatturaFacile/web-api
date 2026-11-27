<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Subcontracting\Http\Controllers\Concerns\InteractsWithSubcontracting;
use Modules\Subcontracting\Http\Requests\QuantityLinesRequest;
use Modules\Subcontracting\Http\Requests\ReceiveGoodsRequest;
use Modules\Subcontracting\Http\Resources\SubcontractingOrderResource;
use Modules\Subcontracting\Services\PassiveSubcontractingService;
use OpenApi\Attributes as OA;

/**
 * Passive subcontracting flow: dispatch with the DDT Conto lavoro, receipt with backflush and cost capitalization, return of unused material.
 */
final class PassiveFlowController extends Controller
{
    use ApiResponse;
    use InteractsWithSubcontracting;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    public function __construct(private readonly PassiveSubcontractingService $passive) {}

    #[OA\Post(path: '/api/v1/subcontracting/orders/{id}/dispatch', operationId: 'dispatchSubcontractingMaterials', summary: 'Send components to the vendor virtual warehouse and emit the DDT Conto lavoro', security: self::SECURITY, tags: ['Subcontracting'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The order')])]
    public function dispatch(QuantityLinesRequest $request, string $id): JsonResponse
    {
        $order = $this->passive->dispatch($this->ownedOrder($id), $request->validated(), $this->userId());

        return $this->successResponse(new SubcontractingOrderResource($order), $this->message('dispatched'));
    }

    #[OA\Post(path: '/api/v1/subcontracting/orders/{id}/receive-preview', operationId: 'previewSubcontractingReceipt', summary: 'Backflush and cost capitalization a receipt would produce, without writing', security: self::SECURITY, tags: ['Subcontracting'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The receipt plan')])]
    public function preview(ReceiveGoodsRequest $request, string $id): JsonResponse
    {
        Gate::authorize('view', $this->ownedOrder($id));

        return $this->successResponse($this->passive->preview($this->ownedOrder($id), $request->validated()), $this->message('loaded'));
    }

    #[OA\Post(path: '/api/v1/subcontracting/orders/{id}/receive', operationId: 'receiveSubcontractedGoods', summary: 'Receive processed goods, backflush components, declare scrap and capitalize the service fee', security: self::SECURITY, tags: ['Subcontracting'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The order')])]
    public function receive(ReceiveGoodsRequest $request, string $id): JsonResponse
    {
        $order = $this->passive->receive($this->ownedOrder($id), $request->validated(), $this->userId());

        return $this->successResponse(new SubcontractingOrderResource($order), $this->message('received'));
    }

    #[OA\Post(path: '/api/v1/subcontracting/orders/{id}/return-unused', operationId: 'returnUnusedSubcontractedMaterials', summary: 'Bring unused material back with the DDT Reso da terzista', security: self::SECURITY, tags: ['Subcontracting'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The order')])]
    public function returnUnused(QuantityLinesRequest $request, string $id): JsonResponse
    {
        $order = $this->passive->returnUnused($this->ownedOrder($id), $request->validated(), $this->userId());

        return $this->successResponse(new SubcontractingOrderResource($order), $this->message('returned'));
    }
}

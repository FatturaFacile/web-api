<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Subcontracting\Http\Controllers\Concerns\InteractsWithSubcontracting;
use Modules\Subcontracting\Http\Requests\DeliverActiveGoodsRequest;
use Modules\Subcontracting\Http\Requests\QuantityLinesRequest;
use Modules\Subcontracting\Http\Requests\RecordActiveProductionRequest;
use Modules\Subcontracting\Http\Resources\SubcontractingOrderResource;
use Modules\Subcontracting\Services\ActiveSubcontractingService;
use OpenApi\Attributes as OA;

/**
 * Active subcontracting flow: customer material in at zero value, production on it, processed goods back with the DDT Reso lavorato.
 */
final class ActiveFlowController extends Controller
{
    use ApiResponse;
    use InteractsWithSubcontracting;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    public function __construct(private readonly ActiveSubcontractingService $active) {}

    #[OA\Post(path: '/api/v1/subcontracting/orders/{id}/receive-customer-materials', operationId: 'receiveCustomerMaterials', summary: 'Receive customer-owned material into the segregated customer-goods warehouse at zero value', security: self::SECURITY, tags: ['Subcontracting'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The order')])]
    public function receiveMaterials(QuantityLinesRequest $request, string $id): JsonResponse
    {
        $order = $this->active->receiveCustomerMaterials($this->ownedOrder($id), $request->validated(), $this->userId());

        return $this->successResponse(new SubcontractingOrderResource($order), $this->message('received'));
    }

    #[OA\Post(path: '/api/v1/subcontracting/orders/{id}/production', operationId: 'recordActiveSubcontractingProduction', summary: 'Record the production on the customer material (Mod 064 production order)', security: self::SECURITY, tags: ['Subcontracting'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The order')])]
    public function production(RecordActiveProductionRequest $request, string $id): JsonResponse
    {
        $order = $this->active->recordProduction($this->ownedOrder($id), $request->validated(), $this->userId());

        return $this->successResponse(new SubcontractingOrderResource($order), $this->message('produced'));
    }

    #[OA\Post(path: '/api/v1/subcontracting/orders/{id}/deliver', operationId: 'deliverActiveSubcontractedGoods', summary: 'Return processed goods with the DDT Reso lavorato and prepare the service invoice', security: self::SECURITY, tags: ['Subcontracting'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The order')])]
    public function deliver(DeliverActiveGoodsRequest $request, string $id): JsonResponse
    {
        $order = $this->active->deliver($this->ownedOrder($id), $request->validated(), $this->userId());

        return $this->successResponse(new SubcontractingOrderResource($order), $this->message('delivered'));
    }
}

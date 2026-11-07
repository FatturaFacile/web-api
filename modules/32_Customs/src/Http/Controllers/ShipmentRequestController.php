<?php

declare(strict_types=1);

namespace Modules\Customs\Http\Controllers;

use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Customs\Enums\ShipmentRequestStatus;
use Modules\Customs\Exceptions\CustomsException;
use Modules\Customs\Http\Requests\AcceptShipmentRequest;
use Modules\Customs\Http\Resources\CustomsRecordResource;
use Modules\Customs\Models\CustomsShipmentRequest;
use Modules\Customs\Services\DossierService;
use OpenApi\Attributes as OA;

/**
 * The shipment requests sent by customers through the portal.
 */
final class ShipmentRequestController extends CustomsController
{
    public function __construct(TenantContext $tenantContext, private readonly DossierService $dossiers)
    {
        parent::__construct($tenantContext);
    }

    /**
     * List shipment requests.
     */
    #[OA\Get(path: '/api/v1/customs/shipment-requests', operationId: 'listCustomsShipmentRequests', description: 'Return the shipment requests of the customers, newest first.', summary: 'List shipment requests', security: [['bearerAuth' => []]], tags: ['Customs'], parameters: [new OA\Parameter(name: 'status', description: 'Only this status', in: 'query', required: false, schema: new OA\Schema(type: 'string'))], responses: [
        new OA\Response(response: 200, description: 'Requests', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CustomsShipmentRequest::class);

        $query = CustomsShipmentRequest::query()
            ->when($request->filled('status'), static fn ($query) => $query->where('status', (string) $request->query('status')))
            ->latest();

        return CustomsRecordResource::collection($query->paginate($this->perPage($request)));
    }

    /**
     * Accept a shipment request.
     */
    #[OA\Post(path: '/api/v1/customs/shipment-requests/{id}/accept', operationId: 'acceptCustomsShipmentRequest', description: 'Open a dossier from a shipment request.', summary: 'Accept shipment request', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CustomsAcceptShipmentRequest')), tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsRecordId')], responses: [
        new OA\Response(response: 200, description: 'Dossier opened', content: new OA\JsonContent(ref: '#/components/schemas/CustomsDossierResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CustomsUnprocessable', response: 422),
    ])]
    public function accept(AcceptShipmentRequest $request, string $id): CustomsRecordResource
    {
        $shipment = CustomsShipmentRequest::query()->findOrFail($id);
        $this->authorize('update', $shipment);

        $dossier = $this->dossiers->acceptRequest($shipment, $request->validated('currency'), $this->actorId($request));
        $dossier->wasRecentlyCreated = false;

        return new CustomsRecordResource($dossier);
    }

    /**
     * Refuse a shipment request.
     */
    #[OA\Post(path: '/api/v1/customs/shipment-requests/{id}/refuse', operationId: 'refuseCustomsShipmentRequest', description: 'Refuse a shipment request.', summary: 'Refuse shipment request', security: [['bearerAuth' => []]], tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsRecordId')], responses: [
        new OA\Response(response: 200, description: 'Request refused', content: new OA\JsonContent(ref: '#/components/schemas/CustomsRecordResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CustomsUnprocessable', response: 422),
    ])]
    public function refuse(string $id): CustomsRecordResource
    {
        $shipment = CustomsShipmentRequest::query()->findOrFail($id);
        $this->authorize('update', $shipment);

        if ($shipment->status !== ShipmentRequestStatus::Pending) {
            throw new CustomsException('The shipment request has already been answered.');
        }

        $shipment->update(['status' => ShipmentRequestStatus::Refused]);

        return new CustomsRecordResource($shipment);
    }
}

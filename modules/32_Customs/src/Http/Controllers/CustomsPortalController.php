<?php

declare(strict_types=1);

namespace Modules\Customs\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PortalAccessToken;
use App\Services\Portal\PortalTokenService;
use Illuminate\Http\JsonResponse;
use Modules\Customs\Enums\ShipmentRequestStatus;
use Modules\Customs\Http\Requests\PortalShipmentRequest;
use Modules\Customs\Models\CustomsDossier;
use Modules\Customs\Models\CustomsShipmentRequest;
use OpenApi\Attributes as OA;

/**
 * What a customer sees of its shipments through the magic link of the portal. Costs and margins never leave the module.
 */
final class CustomsPortalController extends Controller
{
    /** @var list<string> */
    private const array ALLOWED_TOKEN_TYPES = ['dashboard', 'customs'];

    public function __construct(private readonly PortalTokenService $tokens) {}

    /**
     * List the dossiers of the customer.
     */
    #[OA\Get(path: '/api/v1/portal/customs/{token}/dossiers', operationId: 'portalCustomsDossiers', description: 'Return the shipments of the customer. Public endpoint: the token is the credential.', summary: 'Customer shipments', security: [], tags: ['Customer Portal'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsPortalToken')], responses: [
        new OA\Response(response: 200, description: 'Shipments', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 403, description: 'The link does not open this area', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Unknown link', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 410, description: 'The link has expired', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function dossiers(string $token): JsonResponse
    {
        $access = $this->resolve($token);
        $this->tokens->logActivity($access, 'customs_dossiers_viewed');

        $dossiers = CustomsDossier::query()
            ->where('customer_contact_id', $access->contact_id)
            ->latest()
            ->limit(100)
            ->get(['id', 'dossier_number', 'status', 'service_type', 'direction', 'origin_country', 'destination_country', 'gross_weight_kg', 'volume_m3', 'packages', 'created_at']);

        return response()->json(['data' => $dossiers]);
    }

    /**
     * List the shipment requests of the customer.
     */
    #[OA\Get(path: '/api/v1/portal/customs/{token}/shipment-requests', operationId: 'portalCustomsShipmentRequests', description: 'Return the shipment requests of the customer. Public endpoint: the token is the credential.', summary: 'Customer shipment requests', security: [], tags: ['Customer Portal'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsPortalToken')], responses: [
        new OA\Response(response: 200, description: 'Requests', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 403, description: 'The link does not open this area', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Unknown link', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 410, description: 'The link has expired', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function requests(string $token): JsonResponse
    {
        $access = $this->resolve($token);

        return response()->json(['data' => CustomsShipmentRequest::query()->where('contact_id', $access->contact_id)->latest()->limit(100)->get()]);
    }

    /**
     * Ask for a shipment.
     */
    #[OA\Post(path: '/api/v1/portal/customs/{token}/shipment-requests', operationId: 'portalStoreCustomsShipmentRequest', description: 'Send a shipment request to the forwarder. Public endpoint: the token is the credential.', summary: 'Request a shipment', security: [], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CustomsPortalShipmentRequest')), tags: ['Customer Portal'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsPortalToken')], responses: [
        new OA\Response(response: 201, description: 'Request sent', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 403, description: 'The link does not open this area', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Unknown link', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 410, description: 'The link has expired', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function storeRequest(PortalShipmentRequest $request, string $token): JsonResponse
    {
        $access = $this->resolve($token);

        $shipment = CustomsShipmentRequest::query()->create([
            ...$request->validated(),
            'contact_id' => $access->contact_id,
            'status' => ShipmentRequestStatus::Pending,
        ]);

        $this->tokens->logActivity($access, 'customs_shipment_requested', CustomsShipmentRequest::class, (string) $shipment->id);

        return response()->json(['data' => $shipment->refresh()], 201);
    }

    private function resolve(string $token): PortalAccessToken
    {
        $access = $this->tokens->resolveToken($token);

        abort_unless(in_array($access->token_type, self::ALLOWED_TOKEN_TYPES, true), 403, 'This link does not open the shipments of the portal.');

        return $access;
    }
}

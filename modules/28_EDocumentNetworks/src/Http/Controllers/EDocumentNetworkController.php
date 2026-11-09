<?php

declare(strict_types=1);

namespace Modules\EDocumentNetworks\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\EDocumentNetworks\DTOs\EDocumentEnvelope;
use Modules\EDocumentNetworks\Enums\Network;
use Modules\EDocumentNetworks\Exceptions\NetworkNotAvailableException;
use Modules\EDocumentNetworks\Http\Requests\SendEDocumentRequest;
use Modules\EDocumentNetworks\Http\Resources\TransmissionResource;
use Modules\EDocumentNetworks\Models\EDocumentTransmission;
use Modules\EDocumentNetworks\Services\EDocumentGatewayManager;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP endpoints of the edocument network flows of the EDocumentNetworks module.
 */
final class EDocumentNetworkController extends Controller
{
    use ApiResponse;

    /**
     * Inject the collaborators.
     */
    public function __construct(
        private readonly EDocumentGatewayManager $gateways,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * List networks.
     */
    #[OA\Get(
        path: '/api/v1/edocument-networks',
        operationId: 'listEDocumentNetworks',
        description: 'The networks and whether a driver can run. None is connected to a real provider.',
        summary: 'List networks',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Document Networks'],
        responses: [
            new OA\Response(response: 200, description: 'Networks', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/EDocumentNetworkStatus'))])),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(): JsonResponse
    {
        return $this->successResponse(array_map(function (Network $network): array {
            $gateway = $this->gateways->for($network);

            return ['network' => $network->value, 'label' => $network->label(), 'driver' => $gateway->name(), 'is_available' => $gateway->isAvailable()];
        }, Network::cases()));
    }

    /**
     * List transmissions.
     */
    #[OA\Get(
        path: '/api/v1/edocument-networks/transmissions',
        operationId: 'listEDocumentTransmissions',
        description: 'Documents handed to a driver, latest first, paginated. Filter by `network`, `status` or `document_reference`.',
        summary: 'List transmissions',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Document Networks'],
        parameters: [
            new OA\Parameter(name: 'network', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['ebill', 'peppol', 'nso'])),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'document_reference', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 100, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Transmissions', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/EDocumentTransmissionResource'))])),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function transmissions(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'network' => ['nullable', Rule::enum(Network::class)],
            'status' => ['nullable', 'string', 'max:40'],
            'document_reference' => ['nullable', 'string', 'max:190'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = EDocumentTransmission::query()
            ->when($filters['network'] ?? null, static fn ($query, string $network) => $query->where('network', $network))
            ->when($filters['status'] ?? null, static fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['document_reference'] ?? null, static fn ($query, string $reference) => $query->where('document_reference', $reference))
            ->latest()
            ->paginate((int) ($filters['per_page'] ?? 100));

        return $this->successResponse(
            TransmissionResource::collection($page->getCollection()),
            'Transmissions retrieved',
            meta: ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage()],
        );
    }

    /**
     * Show one transmission.
     */
    #[OA\Get(
        path: '/api/v1/edocument-networks/transmissions/{id}',
        operationId: 'getEDocumentTransmission',
        description: 'One transmission of the active tenant: the document reference, the recipient, the SHA-256 of the payload handed over, the driver that took it and the identifier it returned.',
        summary: 'Get a transmission',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Document Networks'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The transmission', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/EDocumentTransmissionResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        return $this->successResponse(new TransmissionResource(EDocumentTransmission::findOrFail($id)), 'Transmission retrieved');
    }

    /**
     * Send document.
     */
    #[OA\Post(
        path: '/api/v1/edocument-networks/{network}/transmissions',
        operationId: 'sendEDocument',
        description: 'Hand a document to the driver of a network. Without a provider contract the answer is 503 and nothing is recorded as sent.',
        summary: 'Send document',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SendEDocumentRequest')),
        tags: ['Document Networks'],
        parameters: [new OA\Parameter(name: 'network', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['ebill', 'peppol', 'nso']))],
        responses: [
            new OA\Response(response: 201, description: 'Accepted by the driver', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/EDocumentTransmissionResource')])),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'The same payload was already handed to this network for this document reference', content: new OA\JsonContent(ref: '#/components/schemas/ApiProblemDetailsResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
            new OA\Response(response: 503, description: 'No provider contract', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function send(SendEDocumentRequest $request, string $network): JsonResponse
    {
        $gateway = $this->gateways->for(Network::from($network));
        $payload = base64_decode((string) $request->validated('payload_base64'), true);

        if ($payload === false) {
            throw ValidationException::withMessages(['payload_base64' => 'This is not valid base64.']);
        }

        $digest = hash('sha256', $payload);

        if (EDocumentTransmission::query()->where('network', $network)->where('document_reference', $request->validated('document_reference'))->where('payload_sha256', $digest)->exists()) {
            return $this->problemDetailsResponse('Already transmitted', 'This exact document was already handed to this network: it is not sent twice.', Response::HTTP_CONFLICT);
        }

        try {
            $receipt = $gateway->send($this->tenantContext->getTenant(), new EDocumentEnvelope(
                $request->validated('document_reference'),
                $request->validated('recipient_identifier'),
                $payload
            ));
        } catch (NetworkNotAvailableException $exception) {
            return $this->problemDetailsResponse('Network not available', $exception->getMessage(), Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $transmission = EDocumentTransmission::create([
            'network' => $network,
            'document_reference' => $request->validated('document_reference'),
            'recipient_identifier' => $request->validated('recipient_identifier'),
            'payload_sha256' => $digest,
            'status' => $receipt['status'],
            'external_id' => $receipt['external_id'],
            'driver' => $gateway->name(),
            'sent_by' => $request->user()->id,
        ]);

        return $this->successResponse(new TransmissionResource($transmission), 'Document handed to the driver', Response::HTTP_CREATED);
    }
}

<?php

declare(strict_types=1);

namespace Modules\Contracts\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Contracts\Enums\ContractKind;
use Modules\Contracts\Http\Controllers\Concerns\InteractsWithContractAuthoring;
use Modules\Contracts\Http\Requests\DeclineSignatureRequest;
use Modules\Contracts\Http\Requests\SignContractRequest;
use Modules\Contracts\Http\Requests\StoreSignatureRequestRequest;
use Modules\Contracts\Http\Resources\SignatureRequestResource;
use Modules\Contracts\Models\ContractDocumentVersion;
use Modules\Contracts\Models\ContractSignatureRequest;
use Modules\Contracts\Services\ContractRegister;
use Modules\Contracts\Services\SignatureService;
use OpenApi\Attributes as OA;

/**
 * E-signature requests of contract text versions: signers in order, provider, signing and the audit trail.
 */
final class SignatureRequestController extends Controller
{
    use InteractsWithContractAuthoring;

    public function __construct(private readonly SignatureService $signatures, private readonly ContractRegister $register) {}

    #[OA\Get(path: '/api/v1/contract-authoring/signature-providers', operationId: 'listContractSignatureProviders', description: 'Names of the available providers; "internal" is built in and needs no external service.', summary: 'List signature providers', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Contract Authoring'], responses: [
        new OA\Response(response: 200, description: 'Provider names'),
        new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
    ])]
    public function providers(): JsonResponse
    {
        $this->authorize('viewAny', ContractSignatureRequest::class);

        return response()->json(['data' => $this->signatures->providerNames()]);
    }

    #[OA\Get(path: '/api/v1/contract-authoring/contracts/{kind}/{id}/signature-requests', operationId: 'listContractSignatureRequests', description: 'Signature requests of the contract with their signers, newest first.', summary: 'List signature requests', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Contract Authoring'], parameters: [new OA\Parameter(name: 'kind', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['customer', 'supplier'])), new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Requests', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ContractSignatureRequestResource'))])),
        new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ContractNotFound', response: 404),
    ])]
    public function index(string $kind, string $id): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ContractSignatureRequest::class);
        $this->register->find(ContractKind::from($kind), $id);

        return SignatureRequestResource::collection(ContractSignatureRequest::query()->with('signers')->where('tenant_id', $this->tenantId())->where('contract_kind', $kind)->where('contract_id', $id)->orderByDesc('created_at')->get());
    }

    #[OA\Post(path: '/api/v1/contract-authoring/contracts/{kind}/{id}/signature-requests', operationId: 'storeContractSignatureRequest', description: 'Create a draft request for a text version with the signers in order (sequential or parallel). One pending request per version.', summary: 'Create a signature request', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ContractSignatureRequestCreateRequest')), tags: ['Contract Authoring'], parameters: [new OA\Parameter(name: 'kind', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['customer', 'supplier'])), new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 201, description: 'Request created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ContractSignatureRequestResource')])),
        new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ContractNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ContractUnprocessable', response: 422),
    ])]
    public function store(StoreSignatureRequestRequest $request, string $kind, string $id): JsonResponse
    {
        $this->authorize('create', ContractSignatureRequest::class);
        $data = $request->validated();
        $version = ContractDocumentVersion::query()->findOrFail($data['document_version_id']);
        $created = $this->signatures->create(ContractKind::from($kind), $id, $version, $data['signers'], $data['order_mode'] ?? 'sequential', $data['expires_on'] ?? null, $data['provider'] ?? 'internal', $this->userId());

        return (new SignatureRequestResource($created))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/contract-authoring/signature-requests/{id}', operationId: 'showContractSignatureRequest', description: 'The request with signers and the audit trail.', summary: 'Show a signature request', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Contract Authoring'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Request', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ContractSignatureRequestResource')])),
        new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ContractNotFound', response: 404),
    ])]
    public function show(string $id): SignatureRequestResource
    {
        $signatureRequest = ContractSignatureRequest::query()->with(['signers', 'events'])->findOrFail($id);
        $this->authorize('view', $signatureRequest);

        return new SignatureRequestResource($signatureRequest);
    }

    #[OA\Post(path: '/api/v1/contract-authoring/signature-requests/{id}/send', operationId: 'sendContractSignatureRequest', description: 'Hand the draft to its provider. With the internal provider the response carries one signing token per signer id, shown once; only its hash is stored.', summary: 'Send a signature request', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Contract Authoring'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Request sent', content: new OA\JsonContent(ref: '#/components/schemas/ContractSignatureSendResponse')),
        new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ContractNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ContractUnprocessable', response: 422),
    ])]
    public function send(string $id): JsonResponse
    {
        $signatureRequest = ContractSignatureRequest::query()->findOrFail($id);
        $this->authorize('update', $signatureRequest);
        $result = $this->signatures->send($signatureRequest, $this->userId());

        return response()->json(['data' => (new SignatureRequestResource($result['request']))->resolve(), 'signing_tokens' => $result['tokens']]);
    }

    #[OA\Post(path: '/api/v1/contract-authoring/signature-requests/{id}/sign', operationId: 'signContractSignatureRequest', description: 'A signer confirms with the personal token. Sequential requests require the previous signers first; the last signature completes the request.', summary: 'Record a signature', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ContractSignRequest')), tags: ['Contract Authoring'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Request', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ContractSignatureRequestResource')])),
        new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ContractNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ContractUnprocessable', response: 422),
    ])]
    public function sign(SignContractRequest $request, string $id): SignatureRequestResource
    {
        $signatureRequest = ContractSignatureRequest::query()->findOrFail($id);
        $this->authorize('update', $signatureRequest);

        return new SignatureRequestResource($this->signatures->sign($signatureRequest, $request->validated('token'), $request->ip(), $this->userId()));
    }

    #[OA\Post(path: '/api/v1/contract-authoring/signature-requests/{id}/decline', operationId: 'declineContractSignatureRequest', description: 'A signer declines with the personal token and a reason; the request ends declined.', summary: 'Record a refusal', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ContractSignatureDeclineRequest')), tags: ['Contract Authoring'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Request', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ContractSignatureRequestResource')])),
        new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ContractNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ContractUnprocessable', response: 422),
    ])]
    public function decline(DeclineSignatureRequest $request, string $id): SignatureRequestResource
    {
        $signatureRequest = ContractSignatureRequest::query()->findOrFail($id);
        $this->authorize('update', $signatureRequest);
        $data = $request->validated();

        return new SignatureRequestResource($this->signatures->decline($signatureRequest, $data['token'], $data['reason'], $this->userId()));
    }

    #[OA\Post(path: '/api/v1/contract-authoring/signature-requests/{id}/cancel', operationId: 'cancelContractSignatureRequest', description: 'Withdraw a draft or sent request (the provider is told).', summary: 'Cancel a signature request', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Contract Authoring'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Request', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ContractSignatureRequestResource')])),
        new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ContractNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ContractUnprocessable', response: 422),
    ])]
    public function cancel(string $id): SignatureRequestResource
    {
        $signatureRequest = ContractSignatureRequest::query()->findOrFail($id);
        $this->authorize('update', $signatureRequest);

        return new SignatureRequestResource($this->signatures->cancel($signatureRequest, $this->userId()));
    }
}

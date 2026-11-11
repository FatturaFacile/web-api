<?php

declare(strict_types=1);

namespace Modules\Integrations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Integrations\Http\Resources\WebhookDeliveryResource;
use Modules\Integrations\Http\Resources\WebhookEndpointResource;
use Modules\Integrations\Models\WebhookEndpoint;
use Modules\Integrations\OpenApi\WebhookDocumentation;
use Modules\Integrations\Webhooks\WebhookDeliveryService;
use Modules\Integrations\Webhooks\WebhookEndpointManager;
use Modules\Integrations\Webhooks\WebhookEventCatalog;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Outbound webhooks of the active tenant. Access is decided by the module.access:integrations middleware of the routes
 * (owners and admins; read for GET, write for the rest, and the same scopes for an API key).
 */
final class WebhookEndpointController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly WebhookEndpointManager $endpoints,
        private readonly WebhookDeliveryService $deliveries,
    ) {}

    #[OA\Get(path: '/api/v1/integrations/webhook-events', operationId: 'listWebhookEvents', summary: 'Webhook event catalogue', description: 'The events an endpoint can subscribe to, with the schema of their payload "data" (components WebhookData...), the payload versions and the signature scheme. Only events that the application already raises are listed. '.WebhookDocumentation::SignatureRecipe.' '.WebhookDocumentation::RetryPolicy, security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Integrations'], responses: [
        new OA\Response(response: 200, description: 'The catalogue', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 403, description: 'Only owners and admins', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function events(): JsonResponse
    {
        $events = [];

        foreach (WebhookEventCatalog::all() as $name => $definition) {
            $events[] = [
                'name' => $name,
                'label' => $definition['label'],
                'module' => $definition['module'],
                'description' => $definition['description'],
                'data_schema' => '#/components/schemas/WebhookData'.str_replace(' ', '', ucwords(str_replace(['.', '_'], ' ', $name))),
                'data_fields' => $definition['fields'],
            ];
        }

        return $this->successResponse([
            'payload_versions' => config('webhooks.payload_versions'),
            'default_payload_version' => config('webhooks.default_payload_version'),
            'signature' => [
                'header' => 'X-FF-Webhook-Signature',
                'format' => 't=<unix timestamp>,v1=<hex hmac-sha256>[,v1=<hex>]',
                'signed_string' => '<t>.<raw request body>',
                'algorithm' => 'HMAC-SHA256, key = the signing secret of the endpoint, hex encoded',
                'tolerance_seconds' => config('webhooks.signature_tolerance_seconds'),
                'other_headers' => ['X-FF-Webhook-Id' => 'idempotency key of the delivery', 'X-FF-Webhook-Event' => 'event type', 'X-FF-Webhook-Version' => 'payload version'],
            ],
            'events' => $events,
        ], 'Webhook events retrieved');
    }

    #[OA\Get(path: '/api/v1/integrations/webhook-endpoints', operationId: 'listWebhookEndpoints', summary: 'List webhook endpoints', description: 'Webhook endpoints of the active tenant. Secrets are never returned.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Integrations'], responses: [
        new OA\Response(response: 200, description: 'The endpoints', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 403, description: 'Only owners and admins', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function index(): JsonResponse
    {
        return $this->successResponse(WebhookEndpointResource::collection(WebhookEndpoint::query()->latest()->get()), 'Webhook endpoints retrieved');
    }

    #[OA\Post(path: '/api/v1/integrations/webhook-endpoints', operationId: 'createWebhookEndpoint', summary: 'Create webhook endpoint', description: 'Subscribes a URL to a set of events. The signing secret is returned only by this call. SSRF protection: https only, no credentials, and every address of the host must be public. '.WebhookDocumentation::SignatureRecipe, security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WebhookEndpointRequest')), tags: ['Integrations'], responses: [
        new OA\Response(response: 201, description: 'Endpoint created, with its signing secret', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 403, description: 'Only owners and admins', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error or unsafe URL', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(true));

        $created = $this->endpoints->create($data, $request->user()?->id);

        return $this->successResponse(new WebhookEndpointResource($created['endpoint'], $created['secret']), 'Webhook endpoint created', Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/integrations/webhook-endpoints/{id}', operationId: 'showWebhookEndpoint', summary: 'Show webhook endpoint', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Integrations'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'The endpoint', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 404, description: 'Not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function show(string $id): JsonResponse
    {
        return $this->successResponse(new WebhookEndpointResource(WebhookEndpoint::findOrFail($id)), 'Webhook endpoint retrieved');
    }

    #[OA\Put(path: '/api/v1/integrations/webhook-endpoints/{id}', operationId: 'updateWebhookEndpoint', summary: 'Update webhook endpoint', description: 'Changes name, URL, description, events or payload version. A new URL is checked like the first one. The secret is not touched: use rotate-secret.', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WebhookEndpointRequest')), tags: ['Integrations'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Endpoint updated', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 404, description: 'Not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error or unsafe URL', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function update(Request $request, string $id): JsonResponse
    {
        $endpoint = WebhookEndpoint::findOrFail($id);

        return $this->successResponse(new WebhookEndpointResource($this->endpoints->update($endpoint, $request->validate($this->rules(false)))), 'Webhook endpoint updated');
    }

    #[OA\Delete(path: '/api/v1/integrations/webhook-endpoints/{id}', operationId: 'deleteWebhookEndpoint', summary: 'Delete webhook endpoint', description: 'Deletes the endpoint and its delivery log. The deletion is recorded in the audit trail.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Integrations'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Endpoint deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 404, description: 'Not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function destroy(string $id): JsonResponse
    {
        $this->endpoints->delete(WebhookEndpoint::findOrFail($id));

        return $this->successResponse(null, 'Webhook endpoint deleted');
    }

    #[OA\Post(path: '/api/v1/integrations/webhook-endpoints/{id}/rotate-secret', operationId: 'rotateWebhookEndpointSecret', summary: 'Rotate signing secret', description: 'Creates a new signing secret, returned only by this call. The previous secret keeps signing too for "overlap_hours" (default 24, at most 168; 0 retires it at once), so the receiver can switch without losing a delivery. '.WebhookDocumentation::SignatureRecipe, security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'overlap_hours', type: 'integer', minimum: 0, maximum: 168, example: 24)])), tags: ['Integrations'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'The endpoint with its new secret', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 404, description: 'Not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function rotateSecret(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['overlap_hours' => ['nullable', 'integer', 'min:0', 'max:'.(int) config('webhooks.max_secret_overlap_hours')]]);
        $endpoint = WebhookEndpoint::findOrFail($id);

        $secret = $this->endpoints->rotateSecret($endpoint, (int) ($data['overlap_hours'] ?? config('webhooks.default_secret_overlap_hours')));

        return $this->successResponse(new WebhookEndpointResource($endpoint->refresh(), $secret), 'Signing secret rotated');
    }

    #[OA\Post(path: '/api/v1/integrations/webhook-endpoints/{id}/enable', operationId: 'enableWebhookEndpoint', summary: 'Enable webhook endpoint', description: 'Re-enables a disabled endpoint (by hand or by the automatic failure limit) and resets its failure counter. Its dead-lettered deliveries can then be redelivered.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Integrations'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Endpoint enabled', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 404, description: 'Not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'The URL is no longer acceptable', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function enable(string $id): JsonResponse
    {
        return $this->successResponse(new WebhookEndpointResource($this->endpoints->enable(WebhookEndpoint::findOrFail($id))), 'Webhook endpoint enabled');
    }

    #[OA\Post(path: '/api/v1/integrations/webhook-endpoints/{id}/disable', operationId: 'disableWebhookEndpoint', summary: 'Disable webhook endpoint', description: 'Stops sending to the endpoint. Deliveries still waiting become dead letters.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Integrations'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Endpoint disabled', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 404, description: 'Not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function disable(string $id): JsonResponse
    {
        return $this->successResponse(new WebhookEndpointResource($this->endpoints->disable(WebhookEndpoint::findOrFail($id), 'Disabled by a user.')), 'Webhook endpoint disabled');
    }

    #[OA\Post(path: '/api/v1/integrations/webhook-endpoints/{id}/test', operationId: 'testWebhookEndpoint', summary: 'Send a test ping', description: 'Sends a signed "webhook.ping" event to the endpoint at once, once (no retries, not counted towards the automatic disable) and returns the delivery with the receiver answer, status code and duration. Works on a disabled endpoint, so a fix can be checked before enabling it.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Integrations'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'The ping delivery with its outcome (status "succeeded" or "dead")', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 404, description: 'Not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function test(string $id): JsonResponse
    {
        $endpoint = WebhookEndpoint::findOrFail($id);
        $delivery = $this->deliveries->ping($endpoint);
        $this->endpoints->recordPing($endpoint, $delivery);

        return $this->successResponse(new WebhookDeliveryResource($delivery->load('attemptLog'), true), 'Test ping sent');
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:100'],
            'url' => [$required, 'string', 'max:2000', 'url:https'],
            'description' => ['nullable', 'string', 'max:500'],
            'events' => [$required, 'array', 'min:1'],
            'events.*' => ['string', 'distinct', Rule::in(WebhookEventCatalog::names())],
            'payload_version' => ['sometimes', 'string', Rule::in(config('webhooks.payload_versions'))],
        ];
    }
}

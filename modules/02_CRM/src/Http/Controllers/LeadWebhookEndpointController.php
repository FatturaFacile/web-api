<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\CRM\Models\LeadWebhookEndpoint;
use OpenApi\Attributes as OA;

/**
 * Manages the Web-to-Lead endpoints of the tenant. The secret is returned only on creation / rotation.
 */
final class LeadWebhookEndpointController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    #[OA\Get(
        path: '/api/v1/crm/lead-webhooks',
        operationId: 'listCrmLeadWebhooks',
        description: 'Web-to-Lead endpoints of the active tenant, newest first. The secret is never returned by the list.',
        summary: 'List lead webhook endpoints',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Lead Webhooks'],
        responses: [
            new OA\Response(response: 200, description: 'Endpoints', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'provider', type: 'string'), new OA\Property(property: 'url', type: 'string'), new OA\Property(property: 'token', type: 'string', format: 'uuid'), new OA\Property(property: 'lead_source_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'duplicate_strategy', type: 'string'), new OA\Property(property: 'field_map', type: 'object', nullable: true), new OA\Property(property: 'is_active', type: 'boolean'), new OA\Property(property: 'received_count', type: 'integer'), new OA\Property(property: 'last_received_at', type: 'string', format: 'date-time', nullable: true)]))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(): JsonResponse
    {
        $endpoints = LeadWebhookEndpoint::where('tenant_id', $this->tenantContext->getTenantId())->latest()->get();

        return response()->json(['data' => $endpoints->map(fn (LeadWebhookEndpoint $e): array => $this->present($e))]);
    }

    #[OA\Post(
        path: '/api/v1/crm/lead-webhooks',
        operationId: 'createCrmLeadWebhook',
        description: 'Creates a Web-to-Lead endpoint and returns its public URL and secret. The secret is returned only on creation and rotation.',
        summary: 'Create lead webhook endpoint',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Lead Webhooks'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['name'], properties: [new OA\Property(property: 'name', type: 'string', maxLength: 150), new OA\Property(property: 'provider', type: 'string', enum: ['generic', 'wordpress', 'webflow', 'meta_ads', 'linkedin'], default: 'generic'), new OA\Property(property: 'lead_source_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'duplicate_strategy', type: 'string', enum: ['merge', 'notify', 'create'], default: 'notify'), new OA\Property(property: 'field_map', type: 'object', nullable: true, description: 'Maps payload keys to lead fields')])),
        responses: [
            new OA\Response(response: 201, description: 'Endpoint created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'provider', type: 'string'), new OA\Property(property: 'url', type: 'string'), new OA\Property(property: 'token', type: 'string', format: 'uuid'), new OA\Property(property: 'lead_source_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'duplicate_strategy', type: 'string'), new OA\Property(property: 'field_map', type: 'object', nullable: true), new OA\Property(property: 'is_active', type: 'boolean'), new OA\Property(property: 'received_count', type: 'integer'), new OA\Property(property: 'last_received_at', type: 'string', format: 'date-time', nullable: true), new OA\Property(property: 'secret', type: 'string', description: 'Shown only now: store it, it cannot be read again')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'provider' => ['nullable', 'in:generic,wordpress,webflow,meta_ads,linkedin'],
            'lead_source_id' => ['nullable', 'uuid'],
            'duplicate_strategy' => ['nullable', 'in:merge,notify,create'],
            'field_map' => ['nullable', 'array'],
            'field_map.*' => ['string'],
        ]);

        $secret = Str::random(48);
        $endpoint = LeadWebhookEndpoint::create([
            'tenant_id' => $this->tenantContext->getTenantId(),
            'name' => $data['name'],
            'provider' => $data['provider'] ?? 'generic',
            'lead_source_id' => $data['lead_source_id'] ?? null,
            'duplicate_strategy' => $data['duplicate_strategy'] ?? 'notify',
            'field_map' => $data['field_map'] ?? null,
            'token' => (string) Str::uuid(),
            'secret' => $secret,
        ]);

        return response()->json($this->present($endpoint) + ['secret' => $secret], 201);
    }

    #[OA\Post(
        path: '/api/v1/crm/lead-webhooks/{id}/rotate-secret',
        operationId: 'rotateCrmLeadWebhookSecret',
        description: 'Generates a new secret for the endpoint and returns it once; deliveries signed with the old secret are rejected from now on.',
        summary: 'Rotate lead webhook secret',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Lead Webhooks'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the endpoint', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Endpoint with the new secret', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'provider', type: 'string'), new OA\Property(property: 'url', type: 'string'), new OA\Property(property: 'token', type: 'string', format: 'uuid'), new OA\Property(property: 'lead_source_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'duplicate_strategy', type: 'string'), new OA\Property(property: 'field_map', type: 'object', nullable: true), new OA\Property(property: 'is_active', type: 'boolean'), new OA\Property(property: 'received_count', type: 'integer'), new OA\Property(property: 'last_received_at', type: 'string', format: 'date-time', nullable: true), new OA\Property(property: 'secret', type: 'string', description: 'Shown only now: store it, it cannot be read again')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function rotateSecret(string $id): JsonResponse
    {
        $endpoint = LeadWebhookEndpoint::where('tenant_id', $this->tenantContext->getTenantId())->findOrFail($id);
        $secret = Str::random(48);
        $endpoint->update(['secret' => $secret]);

        return response()->json($this->present($endpoint) + ['secret' => $secret]);
    }

    #[OA\Delete(
        path: '/api/v1/crm/lead-webhooks/{lead_webhook}',
        operationId: 'deleteCrmLeadWebhook',
        description: 'Deletes (soft) a Web-to-Lead endpoint; its public URL stops accepting deliveries.',
        summary: 'Delete lead webhook endpoint',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Lead Webhooks'],
        parameters: [
            new OA\Parameter(name: 'lead_webhook', in: 'path', required: true, description: 'Identifier of the endpoint', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Endpoint deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        LeadWebhookEndpoint::where('tenant_id', $this->tenantContext->getTenantId())->findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function present(LeadWebhookEndpoint $endpoint): array
    {
        return [
            'id' => $endpoint->id,
            'name' => $endpoint->name,
            'provider' => $endpoint->provider,
            'url' => url('/api/v1/webhooks/leads/'.$endpoint->token),
            'token' => $endpoint->token,
            'lead_source_id' => $endpoint->lead_source_id,
            'duplicate_strategy' => $endpoint->duplicate_strategy,
            'field_map' => $endpoint->field_map,
            'is_active' => $endpoint->is_active,
            'received_count' => $endpoint->received_count,
            'last_received_at' => $endpoint->last_received_at?->toIso8601String(),
        ];
    }
}

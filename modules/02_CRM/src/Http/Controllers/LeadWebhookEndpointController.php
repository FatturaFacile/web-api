<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\CRM\Models\LeadWebhookEndpoint;

/**
 * Manages the Web-to-Lead endpoints of the tenant. The secret is returned only on creation / rotation.
 */
final class LeadWebhookEndpointController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function index(): JsonResponse
    {
        $endpoints = LeadWebhookEndpoint::where('tenant_id', $this->tenantContext->getTenantId())->latest()->get();

        return response()->json(['data' => $endpoints->map(fn (LeadWebhookEndpoint $e): array => $this->present($e))]);
    }

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

    public function rotateSecret(string $id): JsonResponse
    {
        $endpoint = LeadWebhookEndpoint::where('tenant_id', $this->tenantContext->getTenantId())->findOrFail($id);
        $secret = Str::random(48);
        $endpoint->update(['secret' => $secret]);

        return response()->json($this->present($endpoint) + ['secret' => $secret]);
    }

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

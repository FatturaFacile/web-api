<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Modules\CRM\Contracts\LeadIntakeServiceInterface;
use Modules\CRM\DTOs\IntakeResult;
use Modules\CRM\Models\LeadSource;

/**
 * Public web-to-lead endpoints. There is no session: the source is found by its id, the delivery is verified
 * against the source credentials and processed inside the tenant of the source (never a client-sent tenant).
 * Error bodies are deliberately generic.
 */
final class LeadWebhookController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly LeadIntakeServiceInterface $intake,
    ) {}

    public function store(Request $request, string $sourceId): JsonResponse
    {
        $source = $this->findSource($sourceId);
        if ($source === null) {
            return $this->notFound();
        }

        $tenant = Tenant::query()->find($source->tenant_id);
        if (! $source->is_active || ! $tenant instanceof Tenant || ! $this->intake->verify($request, $source)) {
            return $this->unauthorized();
        }

        $payload = $this->payload($request);

        /** @var IntakeResult $result */
        $result = $this->tenantContext->runWithTenant($tenant, fn (): IntakeResult => $this->intake->ingest($source, $payload));

        return response()->json(['data' => $result->toArray()], $result->isCreated() ? 201 : 200);
    }

    public function events(Request $request, string $sourceId): JsonResponse
    {
        $source = $this->findSource($sourceId);
        if ($source === null) {
            return $this->notFound();
        }

        $tenant = Tenant::query()->find($source->tenant_id);
        if (! $source->is_active || ! $tenant instanceof Tenant || ! $this->intake->verify($request, $source)) {
            return $this->unauthorized();
        }

        $payload = $this->payload($request);
        $result = $this->tenantContext->runWithTenant($tenant, fn (): ?array => $this->intake->ingestEvent($source, $payload));

        if ($result === null) {
            return response()->json(['message' => 'Lead not found.'], 404);
        }

        $created = (bool) $result['created'];
        unset($result['created']);

        return response()->json(['data' => $result], $created ? 201 : 200);
    }

    /**
     * Meta webhook subscription handshake: echoes hub.challenge when hub.verify_token is the source API key.
     */
    public function verifySubscription(Request $request, string $sourceId): Response|JsonResponse
    {
        $source = $this->findSource($sourceId);
        if ($source === null) {
            return $this->notFound();
        }

        // PHP turns "hub.mode" into "hub_mode" when parsing the query string.
        $mode = (string) $request->query('hub_mode', '');
        $token = (string) $request->query('hub_verify_token', '');
        $challenge = (string) $request->query('hub_challenge', '');

        if (! $source->is_active || $source->type !== 'meta_lead_ads' || $mode !== 'subscribe' || $challenge === '' || ! $source->verifyApiKey($token)) {
            return $this->unauthorized();
        }

        return response($challenge, 200, ['Content-Type' => 'text/plain']);
    }

    private function findSource(string $sourceId): ?LeadSource
    {
        if (! Str::isUuid($sourceId)) {
            return null;
        }

        // No tenant context exists yet on a public request: bypass only the tenant scope (soft deletes still apply).
        $source = LeadSource::withoutGlobalScope(TenantScope::class)->find($sourceId);

        return $source instanceof LeadSource ? $source : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        if ($request->isJson()) {
            return $request->json()->all();
        }

        return $request->request->all();
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Not found.'], 404);
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json(['message' => 'Unauthorized.'], 401);
    }
}

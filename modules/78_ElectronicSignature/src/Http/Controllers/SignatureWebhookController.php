<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\ElectronicSignature\Enums\ProviderType;
use Modules\ElectronicSignature\Exceptions\SignatureException;
use Modules\ElectronicSignature\Models\SignatureEnvelope;
use Modules\ElectronicSignature\Services\ProviderRegistry;
use Modules\ElectronicSignature\Services\SigningService;
use OpenApi\Attributes as OA;

/**
 * Callbacks of the trust service providers. The envelope the callback names selects the company and its configuration; the
 * body is then authenticated with the webhook secret of that configuration (HMAC over the timestamped body, replay window,
 * single-use event id) before anything changes.
 */
final class SignatureWebhookController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly SigningService $signing,
        private readonly TenantContext $tenantContext,
    ) {}

    #[OA\Post(path: '/api/v1/webhooks/signatures/{provider}', operationId: 'signaturesWebhook', summary: 'Signed callback of a signature provider', tags: ['Electronic Signatures Portal'], parameters: [new OA\Parameter(name: 'provider', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['swisscom', 'infocert', 'namirial']))], responses: [new OA\Response(response: 200, description: 'Callback accepted'), new OA\Response(response: 401, description: 'Not authentic or stale'), new OA\Response(response: 409, description: 'Replayed event')])]
    public function handle(Request $request, string $provider): JsonResponse
    {
        $type = ProviderType::tryFrom($provider);

        if ($type === null || $type === ProviderType::Native) {
            throw new SignatureException('webhook_not_supported', 404);
        }

        $adapter = $this->providers->provider($type);
        $payload = $request->getContent();
        $externalId = $adapter->envelopeIdFromWebhook($payload);
        $envelope = $externalId === null ? null : SignatureEnvelope::withoutGlobalScopes()->where('provider', $type->value)->where('external_id', $externalId)->first();
        $tenant = $envelope === null ? null : Tenant::query()->find($envelope->tenant_id);

        if ($envelope === null || ! $tenant instanceof Tenant) {
            // The same answer as a failed signature check: the endpoint does not reveal which envelopes exist.
            throw new SignatureException('webhook_unauthenticated', 401);
        }

        $this->tenantContext->setTenant($tenant);
        $envelope = SignatureEnvelope::query()->with('providerConfig')->findOrFail($envelope->id);
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower((string) $name)] = (string) ($values[0] ?? '');
        }

        $event = $adapter->parseWebhook($payload, $headers, $envelope->providerConfig ?? throw new SignatureException('webhook_unauthenticated', 401));
        $this->signing->applyWebhook($envelope, $event, $request->ip());

        return $this->successResponse(['received' => true], 'signatures.message.callbackAccepted');
    }
}

<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Modules\CRM\Contracts\LeadServiceInterface;
use Modules\CRM\DTOs\CreateLeadDTO;
use Modules\CRM\Http\Resources\LeadResource;
use Modules\CRM\Models\LeadWebhookEndpoint;
use Modules\CRM\Support\LeadPayloadMapper;
use OpenApi\Attributes as OA;

/**
 * Public Web-to-Lead endpoint (WordPress, Webflow, Meta Ads, LinkedIn Lead Gen, generic JSON).
 * The endpoint is addressed by its token and every delivery must carry the endpoint secret, either as
 * `X-Webhook-Signature: sha256=<hmac of the raw body>`, `X-Webhook-Secret` or `Authorization: Bearer`.
 */
final class LeadWebhookController extends Controller
{
    private const BEHAVIOUR_EVENTS = [
        'email_open' => 'emailOpens',
        'catalog_download' => 'catalogDownloads',
        'site_visit' => 'siteVisits',
    ];

    public function __construct(
        private readonly LeadServiceInterface $leadService,
        private readonly TenantContext $tenantContext,
    ) {}

    /** Meta (Facebook/Instagram) Lead Ads subscription handshake. */
    #[OA\Get(
        path: '/api/v1/webhooks/leads/{token}',
        operationId: 'verifyCrmLeadWebhook',
        description: 'Meta (Facebook/Instagram) Lead Ads subscription handshake: when hub_mode is subscribe and hub_verify_token equals the endpoint secret, the hub_challenge is echoed back as plain text. Public: no bearer token.',
        summary: 'Verify lead webhook subscription',
        tags: ['CRM Lead Webhooks'],
        parameters: [
            new OA\Parameter(name: 'token', in: 'path', required: true, description: 'Public token of the webhook endpoint', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'hub_mode', in: 'query', required: false, description: 'Must be subscribe', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'hub_verify_token', in: 'query', required: false, description: 'Endpoint secret', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'hub_challenge', in: 'query', required: false, description: 'Value echoed back', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Challenge echoed back', content: new OA\MediaType(mediaType: 'text/plain', schema: new OA\Schema(type: 'string'))),
            new OA\Response(response: 403, description: 'Invalid token, mode or verify token', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])),
        ]
    )]
    public function verify(Request $request, string $token): Response|JsonResponse
    {
        $endpoint = $this->findEndpoint($token);

        if ($endpoint === null || $request->query('hub_mode') !== 'subscribe'
            || ! hash_equals((string) $endpoint->secret, (string) $request->query('hub_verify_token'))) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response((string) $request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
    }

    #[OA\Post(
        path: '/api/v1/webhooks/leads/{token}',
        operationId: 'receiveCrmLeadWebhook',
        description: 'Public Web-to-Lead delivery (WordPress, Webflow, Meta Ads, LinkedIn Lead Gen, generic JSON), addressed by endpoint token. Authenticated by the endpoint secret: X-Webhook-Signature (sha256 HMAC of the raw body), X-Hub-Signature-256, X-Webhook-Secret or Authorization Bearer. The payload needs an email or a phone. Returns 201 for a new lead, 200 when it was merged into a duplicate.',
        summary: 'Receive lead from webhook',
        tags: ['CRM Lead Webhooks'],
        parameters: [
            new OA\Parameter(name: 'token', in: 'path', required: true, description: 'Public token of the webhook endpoint', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'X-Webhook-Signature', in: 'header', required: false, description: 'sha256=<hmac of the raw body with the endpoint secret>', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', description: 'Provider payload, mapped to lead fields through the endpoint field_map', additionalProperties: true)),
        responses: [
            new OA\Response(response: 201, description: 'Lead created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'lead', ref: '#/components/schemas/CRMLeadResource'), new OA\Property(property: 'merged', type: 'boolean'), new OA\Property(property: 'duplicate_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'))])),
            new OA\Response(response: 200, description: 'Lead merged into an existing duplicate', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'lead', ref: '#/components/schemas/CRMLeadResource'), new OA\Property(property: 'merged', type: 'boolean'), new OA\Property(property: 'duplicate_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'))])),
            new OA\Response(response: 401, description: 'Invalid signature', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: 404, description: 'Unknown webhook endpoint', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: 422, description: 'Payload without email or phone', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])),
        ]
    )]
    public function receive(Request $request, string $token): JsonResponse
    {
        $endpoint = $this->findEndpoint($token);

        if ($endpoint === null) {
            return response()->json(['message' => 'Unknown webhook endpoint.'], 404);
        }

        if (! $this->isAuthentic($request, $endpoint)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $tenant = Tenant::find($endpoint->tenant_id);
        if ($tenant === null) {
            return response()->json(['message' => 'Unknown webhook endpoint.'], 404);
        }

        $payload = $request->json()->all() ?: $request->all();
        $fields = LeadPayloadMapper::map($payload, $endpoint->field_map);

        if (empty($fields['email']) && empty($fields['phone'])) {
            return response()->json(['message' => 'Payload must contain an email or a phone number.'], 422);
        }

        $this->tenantContext->setTenant($tenant);

        $counters = [];
        $event = $fields['event'] ?? null;
        if ($event !== null && isset(self::BEHAVIOUR_EVENTS[$event])) {
            $counters[self::BEHAVIOUR_EVENTS[$event]] = 1;
        }

        $result = $this->leadService->captureLeadWithOutcome(new CreateLeadDTO(
            tenantId: (string) $tenant->id,
            companyId: (string) $tenant->id,
            firstName: $this->str($fields['first_name'] ?? null, 100),
            lastName: $this->str($fields['last_name'] ?? null, 100),
            companyName: $this->str($fields['company_name'] ?? null, 200),
            email: $this->str($fields['email'] ?? null, 150),
            phone: $this->str($fields['phone'] ?? null, 50),
            title: $this->str($fields['title'] ?? null, 100),
            website: $this->str($fields['website'] ?? null, 255),
            country: strlen((string) ($fields['country'] ?? '')) === 2 ? (string) $fields['country'] : 'IT',
            tags: [$endpoint->provider, 'web_to_lead'],
            customFields: ['webhook_endpoint' => (string) $endpoint->id, 'raw_fields' => $fields['extra'] ?? []],
            vatNumber: $this->str($fields['vat_number'] ?? null, 30),
            industry: $this->str($fields['industry'] ?? null, 100),
            region: $this->str($fields['region'] ?? null, 100),
            productLine: $this->str($fields['product_line'] ?? null, 100),
            companySize: isset($fields['company_size']) ? (int) $fields['company_size'] : null,
            estimatedRevenue: isset($fields['estimated_revenue']) ? (float) $fields['estimated_revenue'] : null,
            emailOpens: $counters['emailOpens'] ?? 0,
            catalogDownloads: $counters['catalogDownloads'] ?? 0,
            siteVisits: $counters['siteVisits'] ?? 0,
            estimatedBudget: isset($fields['estimated_budget']) ? (float) $fields['estimated_budget'] : null,
            leadSourceId: $endpoint->lead_source_id ? (string) $endpoint->lead_source_id : null,
            duplicateStrategy: $endpoint->duplicate_strategy,
        ));

        $endpoint->forceFill(['last_received_at' => now(), 'received_count' => (int) $endpoint->received_count + 1])->save();

        return response()->json([
            'lead' => new LeadResource($result->lead),
            'merged' => $result->merged,
            'duplicate_ids' => $result->duplicates->pluck('id')->values(),
        ], $result->merged ? 200 : 201);
    }

    private function findEndpoint(string $token): ?LeadWebhookEndpoint
    {
        if (! Str::isUuid($token)) {
            return null;
        }

        return LeadWebhookEndpoint::withoutGlobalScopes()
            ->where('token', $token)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->first();
    }

    private function isAuthentic(Request $request, LeadWebhookEndpoint $endpoint): bool
    {
        $secret = (string) $endpoint->secret;

        if ($signature = $request->header('X-Webhook-Signature')) {
            $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

            return hash_equals($expected, (string) $signature);
        }

        // Meta signs with the app secret in X-Hub-Signature-256 using the same scheme.
        if ($signature = $request->header('X-Hub-Signature-256')) {
            return hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), (string) $signature);
        }

        $presented = $request->header('X-Webhook-Secret') ?? $request->bearerToken();

        return $presented !== null && hash_equals($secret, (string) $presented);
    }

    private function str(mixed $value, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr(trim((string) $value), 0, $max);
    }
}

<?php

declare(strict_types=1);

namespace Modules\SDI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\SDI\Enums\SdiChannel;
use Modules\SDI\Http\Requests\UpdateSdiChannelRequest;
use Modules\SDI\Jobs\PollSdiChannelJob;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Electronic invoicing channel of the company: certified mail or an API provider.
 */
final class SdiChannelController extends Controller
{
    use ApiResponse;

    /**
     * Pivot roles allowed to see and change the fiscal channel of a company.
     *
     * @var list<string>
     */
    private const array ManagerRoles = ['owner', 'admin'];

    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Show the SDI channel of the active tenant, without its secrets.
     */
    #[OA\Get(
        path: '/api/v1/settings/sdi-channel',
        operationId: 'getSdiChannel',
        description: 'Return the SDI channel of the active tenant. Passwords and API credentials are never returned, only whether they are set.',
        summary: 'Show SDI channel',
        security: [['bearerAuth' => []]],
        tags: ['SDI'],
        responses: [
            new OA\Response(response: 200, description: 'SDI channel', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Only owners and administrators can manage the channel', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(Request $request): JsonResponse
    {
        $tenant = $this->manageableTenant($request);

        return $this->successResponse($this->present($tenant));
    }

    /**
     * Choose the SDI channel of the active tenant and store its credentials.
     */
    #[OA\Put(
        path: '/api/v1/settings/sdi-channel',
        operationId: 'updateSdiChannel',
        description: 'Configure certified mail (pec) or an API provider (openapi, acube, invoicetronic). Secrets left out keep their stored value.',
        summary: 'Update SDI channel',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['channel'],
                properties: [
                    new OA\Property(property: 'channel', type: 'string', enum: ['pec', 'openapi', 'acube', 'invoicetronic', 'local']),
                    new OA\Property(property: 'environment', type: 'string', enum: ['production', 'sandbox']),
                    new OA\Property(property: 'pec_email', type: 'string', format: 'email', nullable: true),
                    new OA\Property(property: 'pec_smtp_host', type: 'string', nullable: true),
                    new OA\Property(property: 'pec_smtp_port', type: 'integer', nullable: true, example: 465),
                    new OA\Property(property: 'pec_imap_host', type: 'string', nullable: true),
                    new OA\Property(property: 'pec_imap_port', type: 'integer', nullable: true, example: 993),
                    new OA\Property(property: 'pec_username', type: 'string', nullable: true),
                    new OA\Property(property: 'pec_password', type: 'string', format: 'password', nullable: true),
                    new OA\Property(property: 'pec_address', description: 'Dedicated address assigned by SDI, normally detected automatically', type: 'string', nullable: true, example: 'sdi24@pec.fatturapa.it'),
                    new OA\Property(property: 'credentials', description: 'token for openapi, email and password for acube, api_key for invoicetronic', type: 'object', nullable: true),
                    new OA\Property(property: 'webhook_secret', description: 'Generated when omitted; for invoicetronic use the secret given by the provider', type: 'string', nullable: true),
                    new OA\Property(property: 'sign_outgoing', type: 'boolean'),
                    new OA\Property(property: 'zero_rate_nature', description: 'VAT nature code used on zero-rated lines', type: 'string', nullable: true, example: 'N2.2'),
                    new OA\Property(property: 'ade_telematic_address_registered', type: 'boolean'),
                    new OA\Property(property: 'ade_conservation_agreement_at', type: 'string', format: 'date', nullable: true),
                ]
            )
        ),
        tags: ['SDI'],
        responses: [
            new OA\Response(response: 200, description: 'SDI channel updated', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Only owners and administrators can manage the channel', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function update(UpdateSdiChannelRequest $request): JsonResponse
    {
        $tenant = $this->manageableTenant($request);
        $data = $request->validated();

        $tenant->fill(array_filter([
            'sdi_channel' => $data['channel'],
            'sdi_environment' => $data['environment'] ?? null,
            'pec_email' => $data['pec_email'] ?? null,
            'sdi_pec_smtp_host' => $data['pec_smtp_host'] ?? null,
            'sdi_pec_smtp_port' => $data['pec_smtp_port'] ?? null,
            'sdi_pec_imap_host' => $data['pec_imap_host'] ?? null,
            'sdi_pec_imap_port' => $data['pec_imap_port'] ?? null,
            'sdi_pec_username' => $data['pec_username'] ?? null,
            'sdi_pec_password' => $data['pec_password'] ?? null,
            'sdi_pec_address' => isset($data['pec_address']) ? strtolower($data['pec_address']) : null,
            'sdi_api_credentials' => isset($data['credentials']) ? [...(array) $tenant->sdi_api_credentials, ...array_filter($data['credentials'])] : null,
            'sdi_webhook_secret' => $data['webhook_secret'] ?? ($tenant->sdi_webhook_secret ?: Str::random(40)),
            'ade_conservation_agreement_at' => $data['ade_conservation_agreement_at'] ?? null,
        ], fn (mixed $value): bool => $value !== null));

        foreach (['sign_outgoing' => 'sdi_sign_outgoing', 'ade_telematic_address_registered' => 'ade_telematic_address_registered'] as $input => $column) {
            if (array_key_exists($input, $data)) {
                $tenant->{$column} = (bool) $data[$input];
            }
        }

        if (array_key_exists('zero_rate_nature', $data)) {
            $tenant->settings = [...(array) $tenant->settings, 'sdi_zero_rate_nature' => $data['zero_rate_nature']];
        }

        $tenant->save();

        return $this->successResponse($this->present($tenant), __('sdi.messages.channel_updated'));
    }

    /**
     * Queue a read of the channel now, without waiting for the scheduler.
     */
    #[OA\Post(
        path: '/api/v1/settings/sdi-channel/poll',
        operationId: 'pollSdiChannel',
        description: 'Queue a read of receipts and supplier invoices from the channel of the active tenant.',
        summary: 'Read SDI channel now',
        security: [['bearerAuth' => []]],
        tags: ['SDI'],
        responses: [
            new OA\Response(response: 202, description: 'Read queued', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Only owners and administrators can manage the channel', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function poll(Request $request): JsonResponse
    {
        PollSdiChannelJob::dispatch($this->manageableTenant($request)->id);

        return $this->successResponse(null, __('sdi.messages.poll_queued'), Response::HTTP_ACCEPTED);
    }

    private function manageableTenant(Request $request): Tenant
    {
        /** @var Tenant $tenant */
        $tenant = $this->tenantContext->getTenant();

        abort_unless(in_array($request->user()->getRoleInTenant($tenant->id), self::ManagerRoles, true), Response::HTTP_FORBIDDEN);

        return $tenant;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Tenant $tenant): array
    {
        $channel = SdiChannel::tryFrom((string) $tenant->sdi_channel);

        return [
            'channel' => $tenant->sdi_channel,
            'environment' => $tenant->sdi_environment,
            'pec_email' => $tenant->pec_email,
            'pec_smtp_host' => $tenant->sdi_pec_smtp_host,
            'pec_smtp_port' => $tenant->sdi_pec_smtp_port,
            'pec_imap_host' => $tenant->sdi_pec_imap_host,
            'pec_imap_port' => $tenant->sdi_pec_imap_port,
            'pec_username' => $tenant->sdi_pec_username,
            'pec_password_set' => ! empty($tenant->sdi_pec_password),
            'pec_address' => $tenant->sdi_pec_address,
            'pec_first_sent_at' => $tenant->sdi_pec_first_sent_at?->toIso8601String(),
            'credentials_set' => array_keys(array_filter((array) $tenant->sdi_api_credentials)),
            'sign_outgoing' => $tenant->sdi_sign_outgoing,
            'zero_rate_nature' => $tenant->settings['sdi_zero_rate_nature'] ?? null,
            'ade_telematic_address_registered' => $tenant->ade_telematic_address_registered,
            'ade_conservation_agreement_at' => $tenant->ade_conservation_agreement_at?->toDateString(),
            'last_poll_at' => $tenant->sdi_last_poll_at?->toIso8601String(),
            'webhook_url' => $channel?->isApiProvider() ? route('sdi.gateway-webhook', ['tenant' => $tenant->id]) : null,
        ];
    }
}

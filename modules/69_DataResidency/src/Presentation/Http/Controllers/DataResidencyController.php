<?php

declare(strict_types=1);

namespace Modules\DataResidency\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Auth\TwoFactorAuthentication;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Modules\DataResidency\Application\Commands\ConfigureByokOrHyokEnvelopeCommand;
use Modules\DataResidency\Application\Commands\EnforceGeoShardingAndRouteTenantStorageCommand;
use Modules\DataResidency\Application\Commands\RotateOrRevokeByokCustomerMasterKeyCommand;
use Modules\DataResidency\Application\Commands\ValidateCrossBorderTransferCommand;
use Modules\DataResidency\Application\Handlers\ConfigureByokOrHyokEnvelopeHandler;
use Modules\DataResidency\Application\Handlers\EnforceGeoShardingAndRouteTenantStorageHandler;
use Modules\DataResidency\Application\Handlers\GenerateSovereigntyAttestationCertificateHandler;
use Modules\DataResidency\Application\Handlers\GetDataSovereigntyDashboardHandler;
use Modules\DataResidency\Application\Handlers\RotateOrRevokeByokCustomerMasterKeyHandler;
use Modules\DataResidency\Application\Handlers\ValidateCrossBorderTransferHandler;
use Modules\DataResidency\Application\Queries\GenerateSovereigntyAttestationCertificateQuery;
use Modules\DataResidency\Application\Queries\GetDataSovereigntyDashboardQuery;
use Modules\DataResidency\Domain\Enums\JurisdictionZone;
use Modules\DataResidency\Domain\Enums\KeyLifecycleAction;
use Modules\DataResidency\Domain\Enums\KeyManagementMode;
use Modules\DataResidency\Domain\Enums\KmsProviderType;
use Modules\DataResidency\Domain\Exceptions\DataResidencyException;
use Modules\DataResidency\Infrastructure\Models\ByokKeyAccessAudit;
use Modules\DataResidency\Infrastructure\Models\CrossBorderTransferRule;
use Modules\DataResidency\Infrastructure\Models\CustomerManagedKeyEnvelope;
use Modules\DataResidency\Infrastructure\Models\SovereignRegionNode;
use Modules\DataResidency\Infrastructure\Models\SovereigntyComplianceAttestation;
use Modules\DataResidency\Infrastructure\Models\TenantDataResidencyPolicy;
use Modules\DataResidency\Infrastructure\Services\AttestationBundleFactory;
use Modules\DataResidency\Infrastructure\Services\AttestationPdfRenderer;
use Modules\DataResidency\Infrastructure\Services\ByokAuditLogger;
use Modules\DataResidency\Presentation\Http\Requests\ConfigureByokRequest;
use Modules\DataResidency\Presentation\Http\Requests\GenerateAttestationRequest;
use Modules\DataResidency\Presentation\Http\Requests\KillSwitchRequest;
use Modules\DataResidency\Presentation\Http\Requests\ListNodesRequest;
use Modules\DataResidency\Presentation\Http\Requests\RotateByokRequest;
use Modules\DataResidency\Presentation\Http\Requests\SaveResidencyPolicyRequest;
use Modules\DataResidency\Presentation\Http\Requests\ValidateTransferRequest;
use Modules\DataResidency\Presentation\Http\Resources\AttestationResource;
use Modules\DataResidency\Presentation\Http\Resources\AuditEntryResource;
use Modules\DataResidency\Presentation\Http\Resources\KeyEnvelopeResource;
use Modules\DataResidency\Presentation\Http\Resources\ResidencyPolicyResource;
use Modules\DataResidency\Presentation\Http\Resources\SovereignRegionNodeResource;
use Modules\DataResidency\Presentation\Http\Resources\TransferRuleResource;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

final class DataResidencyController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TenantContext $tenantContext) {}

    #[OA\Get(path: '/api/v1/sovereignty/nodes', operationId: 'listSovereignNodes', description: 'The sovereign infrastructure nodes (Swiss and Italian data centers) a policy can bind, filterable by jurisdiction zone and service type, with canton or region, city, provider and certifications.', summary: 'List sovereign infrastructure nodes', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Data Sovereignty'], responses: [new OA\Response(response: 200, description: 'The nodes')])]
    public function nodes(ListNodesRequest $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        $nodes = SovereignRegionNode::query()->withoutGlobalScopes()->whereNull('deleted_at')->where('is_active', true)
            ->where(static fn ($query) => $query->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))
            ->when($request->filled('service_type'), static fn ($query) => $query->where('service_type', (string) $request->input('service_type')))
            ->orderBy('node_code')->get();

        if ($request->filled('jurisdiction_zone')) {
            $allowed = JurisdictionZone::from((string) $request->input('jurisdiction_zone'))->allowedCountries();
            $nodes = $nodes->filter(static fn (SovereignRegionNode $node): bool => in_array($node->country_iso2, $allowed, true))->values();
        }

        return $this->successResponse(SovereignRegionNodeResource::collection($nodes)->resolve());
    }

    #[OA\Get(path: '/api/v1/sovereignty/dashboard', operationId: 'getDataSovereigntyDashboard', description: 'The full sovereignty posture: geo-sharding per tenant and per legal entity (CH/IT/EU), key envelope health, the latest hash-chained KMS audit entries with their integrity check and the blocked cross-border egress attempts.', summary: 'Data sovereignty dashboard', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Data Sovereignty'], responses: [new OA\Response(response: 200, description: 'The posture')])]
    public function dashboard(GetDataSovereigntyDashboardHandler $handler): JsonResponse
    {
        return $this->successResponse($handler->handle(new GetDataSovereigntyDashboardQuery($this->tenantId())));
    }

    #[OA\Get(path: '/api/v1/sovereignty/policies', operationId: 'listResidencyPolicies', description: 'The residency policies of the tenant: its default and the overrides of its legal entities.', summary: 'List residency policies', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Data Sovereignty'], responses: [new OA\Response(response: 200, description: 'The policies')])]
    public function policies(): JsonResponse
    {
        $policies = TenantDataResidencyPolicy::query()->withoutGlobalScopes()->where('tenant_id', $this->tenantId())->whereNull('deleted_at')->orderBy('created_at')->get();

        return $this->successResponse(ResidencyPolicyResource::collection($policies)->resolve());
    }

    #[OA\Post(path: '/api/v1/sovereignty/policies', operationId: 'enforceResidencyPolicy', description: 'Binds the database shard, replicas, object storage, vector store, AI inference, backup vault and queue broker of the tenant (or of one legal entity) to sovereign nodes of a jurisdiction zone and activates the policy. A node outside the zone is refused; a locked policy needs a second approver.', summary: 'Create or enforce a residency policy', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Data Sovereignty'], responses: [new OA\Response(response: 200, description: 'The enforced policy'), new OA\Response(response: 422, description: 'A node is outside the jurisdiction zone')])]
    public function enforcePolicy(SaveResidencyPolicyRequest $request, EnforceGeoShardingAndRouteTenantStorageHandler $handler): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse(
            (new ResidencyPolicyResource($handler->handle(new EnforceGeoShardingAndRouteTenantStorageCommand(
                $this->tenantId(),
                $request->filled('legal_entity_id') ? (string) $request->input('legal_entity_id') : null,
                $this->user($request)->id,
                JurisdictionZone::from((string) $request->input('jurisdiction_zone')),
                (string) $request->input('primary_db_node_id'),
                array_values(array_map('strval', (array) $request->input('replica_db_node_ids', []))),
                (string) $request->input('s3_storage_node_id'),
                (string) $request->input('vector_db_node_id'),
                (string) $request->input('ai_inference_node_id'),
                (string) $request->input('backup_node_id'),
                (string) $request->input('queue_node_id'),
                $request->boolean('enforce_strict_egress_block', true),
                $request->boolean('allow_aggregated_non_pii_consolidation', true),
                $request->filled('second_approver_id') ? (string) $request->input('second_approver_id') : null,
            ))))->resolve(),
            'Residency policy enforced',
        ));
    }

    #[OA\Get(path: '/api/v1/sovereignty/byok', operationId: 'listKeyEnvelopes', description: 'The key envelopes of the tenant, without secret material.', summary: 'List BYOK/HYOK key envelopes', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Data Sovereignty'], responses: [new OA\Response(response: 200, description: 'The envelopes')])]
    public function envelopes(): JsonResponse
    {
        $envelopes = CustomerManagedKeyEnvelope::query()->withoutGlobalScopes()->where('tenant_id', $this->tenantId())->whereNull('deleted_at')->orderBy('created_at')->get();

        return $this->successResponse(KeyEnvelopeResource::collection($envelopes)->resolve());
    }

    #[OA\Post(path: '/api/v1/sovereignty/byok/configure', operationId: 'configureByokEnvelope', description: 'Connects the external KMS/HSM (Securosys Primus, AWS KMS, Azure Key Vault, Google Cloud EKM, HashiCorp Vault, KMIP), proves wrap and unwrap, and stores only the wrapped data key. With dry_run it only tests the handshake. The KMS must be inside the jurisdiction zone of the policy.', summary: 'Configure a BYOK/HYOK envelope', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Data Sovereignty'], responses: [new OA\Response(response: 200, description: 'The envelope or the handshake result'), new OA\Response(response: 502, description: 'The KMS is unreachable')])]
    public function configureByok(ConfigureByokRequest $request, ConfigureByokOrHyokEnvelopeHandler $handler): JsonResponse
    {
        return $this->guarded(function () use ($request, $handler): JsonResponse {
            $result = $handler->handle(new ConfigureByokOrHyokEnvelopeCommand(
                $this->tenantId(),
                $request->filled('legal_entity_id') ? (string) $request->input('legal_entity_id') : null,
                $this->user($request)->id,
                KeyManagementMode::from((string) $request->input('key_management_mode')),
                KmsProviderType::from((string) $request->input('provider_type')),
                (string) $request->input('external_key_uri'),
                $request->filled('kmip_endpoint') ? (string) $request->input('kmip_endpoint') : null,
                array_map('strval', (array) $request->input('credentials', [])),
                (int) $request->input('dek_cache_ttl_seconds', 300),
                (int) $request->input('rotation_interval_days', 90),
                $request->boolean('dry_run'),
            ));

            return $this->successResponse([
                'handshake' => $result['handshake'],
                'envelope' => $result['envelope'] === null ? null : (new KeyEnvelopeResource($result['envelope']))->resolve(),
            ]);
        });
    }

    #[OA\Post(path: '/api/v1/sovereignty/byok/{id}/rotate', operationId: 'rotateByokKey', description: 'Creates a new KEK version in the customer KMS and re-wraps the data key under it: no row or file is re-encrypted.', summary: 'Rotate the key-encryption key', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Data Sovereignty'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The rotated envelope'), new OA\Response(response: 423, description: 'The envelope is locked')])]
    public function rotate(string $id, RotateByokRequest $request, RotateOrRevokeByokCustomerMasterKeyHandler $handler): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse((new KeyEnvelopeResource($handler->handle(new RotateOrRevokeByokCustomerMasterKeyCommand(
            $this->tenantId(), $id, $this->user($request)->id, KeyLifecycleAction::Rotate, null, $request->ip(),
        ))))->resolve(), 'Key rotated'));
    }

    #[OA\Post(path: '/api/v1/sovereignty/byok/{id}/kill-switch', operationId: 'triggerByokKillSwitch', description: 'Emergency suspension of key access, or permanent crypto-shredding of the data key. Requires the typed confirmation phrase and a step-up MFA code. Every encrypted column and file becomes unreadable at once (HTTP 423 from then on).', summary: 'BYOK kill-switch', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Data Sovereignty'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The locked envelope'), new OA\Response(response: 403, description: 'The step-up MFA verification failed'), new OA\Response(response: 422, description: 'The confirmation phrase does not match')])]
    public function killSwitch(string $id, KillSwitchRequest $request, RotateOrRevokeByokCustomerMasterKeyHandler $handler, TwoFactorAuthentication $twoFactor): JsonResponse
    {
        $action = KeyLifecycleAction::from((string) $request->input('action'));
        $phrase = (string) config($action === KeyLifecycleAction::CryptoShred ? 'data_residency.crypto_shred_confirmation' : 'data_residency.kill_switch_confirmation');

        if (trim((string) $request->input('confirmation_phrase')) !== $phrase) {
            return $this->problemDetailsResponse('Confirmation Required', 'The typed confirmation phrase does not match.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = $this->user($request);

        if (! $twoFactor->isEnabled($user) || $twoFactor->verifyCode($user, (string) $request->input('mfa_code'), null) === null) {
            return $this->problemDetailsResponse('Step-up Authentication Failed', 'A valid second-factor code is required for the kill-switch.', Response::HTTP_FORBIDDEN);
        }

        return $this->guarded(fn (): JsonResponse => $this->successResponse((new KeyEnvelopeResource($handler->handle(new RotateOrRevokeByokCustomerMasterKeyCommand(
            $this->tenantId(), $id, $user->id, $action, (string) $request->input('reason'), $request->ip(),
        ))))->resolve(), 'Key access suspended'));
    }

    #[OA\Get(path: '/api/v1/sovereignty/byok/audit-logs', operationId: 'listByokAuditLogs', description: 'The append-only KMS access log, newest first, with the verification of its SHA-256 hash chain in the meta.', summary: 'Hash-chain-verifiable KMS access log', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Data Sovereignty'], responses: [new OA\Response(response: 200, description: 'The log page')])]
    public function auditLogs(Request $request, ByokAuditLogger $logger): JsonResponse
    {
        $tenantId = $this->tenantId();
        $page = ByokKeyAccessAudit::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->when($request->filled('operation'), static fn ($query) => $query->where('operation', (string) $request->query('operation')))
            ->orderByDesc('occurred_at')->orderByDesc('id')->paginate(min(100, max(1, $request->integer('per_page', 25))));

        return $this->successResponse(AuditEntryResource::collection($page->getCollection())->resolve(), 'Operation successful', Response::HTTP_OK, [
            'chain' => $logger->verifyChain($tenantId),
            'pagination' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    #[OA\Get(path: '/api/v1/sovereignty/cross-border-rules', operationId: 'listCrossBorderRules', description: 'The adequacy matrix: the Swiss Federal Council list (nLPD Annex 1) and the EU Commission decisions (GDPR Art. 45), with the tenant overrides.', summary: 'Cross-border adequacy matrix', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Data Sovereignty'], responses: [new OA\Response(response: 200, description: 'The rules')])]
    public function crossBorderRules(): JsonResponse
    {
        $tenantId = $this->tenantId();
        $rules = CrossBorderTransferRule::query()->withoutGlobalScopes()->whereNull('deleted_at')
            ->where(static fn ($query) => $query->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))
            ->orderBy('source_jurisdiction')->orderBy('destination_country_iso2')->get();

        return $this->successResponse(TransferRuleResource::collection($rules)->resolve());
    }

    #[OA\Post(path: '/api/v1/sovereignty/cross-border-rules/validate', operationId: 'validateCrossBorderTransfer', description: 'Tests an outbound endpoint (webhook, connector, AI or export URL) against the residency policy without side effects.', summary: 'Test an outbound endpoint', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Data Sovereignty'], responses: [new OA\Response(response: 200, description: 'The verdict')])]
    public function validateTransfer(ValidateTransferRequest $request, ValidateCrossBorderTransferHandler $handler): JsonResponse
    {
        return $this->successResponse($handler->handle(new ValidateCrossBorderTransferCommand(
            $this->tenantId(),
            $request->filled('legal_entity_id') ? (string) $request->input('legal_entity_id') : null,
            (string) $request->input('purpose'),
            (string) $request->input('target'),
            $request->filled('destination_country_iso2') ? (string) $request->input('destination_country_iso2') : null,
        )));
    }

    #[OA\Post(path: '/api/v1/sovereignty/attestations/generate', operationId: 'generateSovereigntyAttestation', description: 'Issues an Ed25519-signed Data Sovereignty Attestation Certificate of the live topology and key status, with its verifiable JSON bundle.', summary: 'Generate a sovereignty attestation', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Data Sovereignty'], responses: [new OA\Response(response: 201, description: 'The attestation and its bundle')])]
    public function generateAttestation(GenerateAttestationRequest $request, GenerateSovereigntyAttestationCertificateHandler $handler): JsonResponse
    {
        return $this->guarded(function () use ($request, $handler): JsonResponse {
            $result = $handler->handle(new GenerateSovereigntyAttestationCertificateQuery(
                $this->tenantId(),
                $request->filled('legal_entity_id') ? (string) $request->input('legal_entity_id') : null,
                $this->user($request)->id,
            ));

            return $this->successResponse(['attestation' => (new AttestationResource($result['attestation']))->resolve(), 'bundle' => $result['bundle']], 'Attestation issued', Response::HTTP_CREATED);
        });
    }

    #[OA\Get(path: '/api/v1/sovereignty/attestations/{id}', operationId: 'getSovereigntyAttestation', description: 'The attestation with its verifiable JSON bundle.', summary: 'Attestation JSON bundle', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Data Sovereignty'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The attestation')])]
    public function attestation(string $id, AttestationBundleFactory $bundles): JsonResponse
    {
        $attestation = $this->findAttestation($id);

        return $this->successResponse(['attestation' => (new AttestationResource($attestation))->resolve(), 'bundle' => $bundles->bundle($attestation), 'signature_valid' => $bundles->verify($attestation)]);
    }

    #[OA\Get(path: '/api/v1/sovereignty/attestations/{id}/pdf', operationId: 'downloadSovereigntyAttestationPdf', description: 'The auditor-ready PDF certificate in the requested language (it, de, fr, en).', summary: 'Download the attestation PDF', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Data Sovereignty'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The PDF')])]
    public function attestationPdf(string $id, Request $request, AttestationPdfRenderer $renderer): HttpResponse
    {
        $attestation = $this->findAttestation($id);
        $locale = in_array($request->query('locale'), ['it', 'de', 'fr', 'en'], true) ? (string) $request->query('locale') : app()->getLocale();

        return response($renderer->render($attestation, $locale), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$attestation->certificate_number.'.pdf"',
        ]);
    }

    private function findAttestation(string $id): SovereigntyComplianceAttestation
    {
        return SovereigntyComplianceAttestation::query()->withoutGlobalScopes()->where('tenant_id', $this->tenantId())->findOrFail($id);
    }

    private function tenantId(): string
    {
        return (string) $this->tenantContext->getTenantId();
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function guarded(Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (DataResidencyException $exception) {
            return $this->problemDetailsResponse($exception->title(), $exception->getMessage(), $exception->httpStatus(), null, null, [['name' => 'code', 'reason' => $exception->errorCode()]]);
        }
    }
}

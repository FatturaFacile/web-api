<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\ElectronicSignature\Enums\AuthMethod;
use Modules\ElectronicSignature\Enums\EnvelopeStatus;
use Modules\ElectronicSignature\Enums\Jurisdiction;
use Modules\ElectronicSignature\Enums\ProviderJurisdiction;
use Modules\ElectronicSignature\Enums\ProviderType;
use Modules\ElectronicSignature\Enums\RecipientRole;
use Modules\ElectronicSignature\Enums\RecipientStatus;
use Modules\ElectronicSignature\Enums\RoutingMode;
use Modules\ElectronicSignature\Enums\SignableType;
use Modules\ElectronicSignature\Enums\SignatureLevel;
use Modules\ElectronicSignature\Http\Requests\PolicyQueryRequest;
use Modules\ElectronicSignature\Http\Resources\ProviderConfigResource;
use Modules\ElectronicSignature\Http\Resources\TemplateResource;
use Modules\ElectronicSignature\Models\SignatureProviderConfig;
use Modules\ElectronicSignature\Models\SignatureTemplate;
use Modules\ElectronicSignature\Services\ProviderRegistry;
use Modules\ElectronicSignature\Services\SignaturePolicyResolver;
use Modules\ElectronicSignature\Services\SignatureQueryService;
use OpenApi\Attributes as OA;

/**
 * Reference data of the signature screens (enums, provider capabilities, defaults) and the compliance question
 * "which tier does this document need".
 */
final class LookupController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly SignaturePolicyResolver $policy,
        private readonly SignatureQueryService $queries,
    ) {}

    #[OA\Get(path: '/api/v1/signatures/lookups', operationId: 'signaturesLookups', summary: 'Reference data of the signature screens', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Electronic Signatures'], responses: [new OA\Response(response: 200, description: 'Lookups')])]
    public function index(): JsonResponse
    {
        Gate::authorize('signatures.view');

        return $this->successResponse([
            'levels' => SignatureLevel::values(),
            'jurisdictions' => Jurisdiction::values(),
            'provider_jurisdictions' => ProviderJurisdiction::values(),
            'providers' => ProviderType::values(),
            'capabilities' => $this->providers->capabilities(),
            'sequential_only_providers' => array_values(array_map(static fn (ProviderType $type): string => $type->value, array_filter(ProviderType::cases(), static fn (ProviderType $type): bool => $type->signsHashes()))),
            'signable_types' => SignableType::values(),
            'roles' => RecipientRole::values(),
            'recipient_statuses' => RecipientStatus::values(),
            'auth_methods' => AuthMethod::values(),
            'routing_modes' => RoutingMode::values(),
            'statuses' => EnvelopeStatus::values(),
            'policies' => config('electronic_signature.policies'),
            'defaults' => [
                'expiration_days' => (int) config('electronic_signature.default_expiration_days'),
                'reminder_days' => config('electronic_signature.default_reminder_days'),
                'max_recipients' => (int) config('electronic_signature.max_recipients'),
            ],
            'provider_configs' => ProviderConfigResource::collection(SignatureProviderConfig::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get())->resolve(),
            'templates' => TemplateResource::collection(SignatureTemplate::query()->where('is_active', true)->orderBy('name')->get())->resolve(),
        ]);
    }

    #[OA\Get(path: '/api/v1/signatures/policy', operationId: 'signaturesPolicy', summary: 'The minimum signature tier a document needs', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Electronic Signatures'], responses: [new OA\Response(response: 200, description: 'Minimum tier')])]
    public function policy(PolicyQueryRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $amount = isset($validated['amount']) ? (string) $validated['amount'] : null;
        $template = $this->policy->template($validated['document_type'], $amount);

        return $this->successResponse([
            'minimum_level' => $this->policy->minimumLevel($validated['document_type'], $amount)->value,
            'template' => $template === null ? null : new TemplateResource($template),
        ]);
    }

    #[OA\Get(path: '/api/v1/signatures/dashboard', operationId: 'signaturesDashboard', summary: 'KPIs of the signature center', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Electronic Signatures'], responses: [new OA\Response(response: 200, description: 'KPIs')])]
    public function dashboard(): JsonResponse
    {
        Gate::authorize('signatures.view');

        return $this->successResponse($this->queries->dashboard());
    }
}

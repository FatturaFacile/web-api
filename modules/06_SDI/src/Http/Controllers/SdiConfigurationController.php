<?php

declare(strict_types=1);

namespace Modules\SDI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Modules\SDI\Http\Requests\UpdateSdiConfigurationRequest;
use Modules\SDI\Models\SdiConfiguration;
use OpenApi\Attributes as OA;

/**
 * Per-tenant transmission and digital signature settings. Credentials and certificates are write-only.
 */
final class SdiConfigurationController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TenantContext $tenants) {}

    #[OA\Get(path: '/api/v1/settings/sdi', operationId: 'showSdiConfiguration', summary: 'Show SDI and signature settings', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['SDI'], responses: [new OA\Response(response: 200, description: 'Settings')])]
    public function show(): JsonResponse
    {
        return $this->successResponse($this->present($this->current()), 'SDI settings retrieved');
    }

    #[OA\Put(path: '/api/v1/settings/sdi', operationId: 'updateSdiConfiguration', summary: 'Update SDI and signature settings', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['SDI'], responses: [new OA\Response(response: 200, description: 'Settings saved')])]
    public function update(UpdateSdiConfigurationRequest $request): JsonResponse
    {
        $configuration = $this->current();
        $data = $request->safe()->except(['certificate', 'certificate_password']);

        if ($request->hasFile('certificate')) {
            $data['encrypted_certificate'] = json_encode([
                'pkcs12' => base64_encode((string) file_get_contents($request->file('certificate')->getRealPath())),
                'password' => (string) $request->input('certificate_password'),
            ]);
        }

        $configuration->update($data);

        return $this->successResponse($this->present($configuration->refresh()), 'SDI settings saved');
    }

    private function current(): SdiConfiguration
    {
        $tenantId = (string) $this->tenants->getTenantId();

        return SdiConfiguration::query()->firstOrCreate(['tenant_id' => $tenantId]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(SdiConfiguration $c): array
    {
        return [
            ...$c->only(['transmitter_country_code', 'transmitter_fiscal_code', 'progressivo_invio_counter', 'channel_type', 'default_codice_destinatario', 'pec_address', 'certificate_reference', 'auto_sign_on_send', 'legal_archiving_enabled', 'sandbox_simulator_enabled']),
            'signature_mode' => $c->signature_mode->value,
            'has_credentials' => $c->getRawOriginal('encrypted_credentials') !== null,
            'has_certificate' => $c->getRawOriginal('encrypted_certificate') !== null,
        ];
    }
}

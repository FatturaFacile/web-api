<?php

declare(strict_types=1);

namespace Modules\Documents\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Documents\Http\Requests\UpdateAiSettingRequest;
use Modules\Documents\Models\TenantAiSetting;
use Modules\Documents\Services\DocumentAccess;
use OpenApi\Attributes as OA;

/**
 * The explicit choice of a company to send its data to providers outside the installation. Off unless turned on here.
 */
final class AiSettingController extends Controller
{
    use ApiResponse;

    /**
     * Inject the collaborators.
     */
    public function __construct(
        private readonly DocumentAccess $access,
        private readonly TenantContext $tenantContext,
        private readonly AuditLedgerInterface $auditLedger,
    ) {}

    /**
     * Show AI settings.
     */
    #[OA\Get(
        path: '/api/v1/dms/ai-settings',
        operationId: 'getDmsAiSettings',
        description: 'Whether this tenant enabled external AI providers. Owners and administrators only.',
        summary: 'Show AI settings',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Document Management'],
        responses: [
            new OA\Response(response: 200, description: 'AI settings', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'external_providers_enabled', type: 'boolean')])])),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(Request $request): JsonResponse
    {
        $this->access->assertManager($request->user());

        return $this->successResponse(['external_providers_enabled' => (bool) TenantAiSetting::query()->value('external_providers_enabled')]);
    }

    /**
     * Update AI settings.
     */
    #[OA\Put(
        path: '/api/v1/dms/ai-settings',
        operationId: 'updateDmsAiSettings',
        description: 'Enable or disable external AI providers for this tenant. Owners and administrators only.',
        summary: 'Update AI settings',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DmsUpdateAiSettingRequest')),
        tags: ['Document Management'],
        responses: [
            new OA\Response(response: 200, description: 'AI settings updated', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'external_providers_enabled', type: 'boolean')])])),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function update(UpdateAiSettingRequest $request): JsonResponse
    {
        $this->access->assertManager($request->user());

        $setting = TenantAiSetting::updateOrCreate(
            ['tenant_id' => $this->tenantContext->getTenantId()],
            ['external_providers_enabled' => $request->boolean('external_providers_enabled'), 'changed_by' => $request->user()->id, 'changed_at' => now()]
        );

        $this->auditLedger->record('tenant_ai_external_providers_changed', $setting, null, ['enabled' => $setting->external_providers_enabled], 'MODULE_25_DOCUMENTS');

        return $this->successResponse(['external_providers_enabled' => $setting->external_providers_enabled], 'AI settings updated');
    }
}

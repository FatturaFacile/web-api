<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePortalSettingRequest;
use App\Http\Responses\ApiResponse;
use App\Models\PortalSetting;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * White-label and security settings of the customer portal, edited by the staff of the tenant.
 */
class PortalSettingController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TenantContext $tenantContext) {}

    #[OA\Get(path: '/api/v1/crm/portal-settings', operationId: 'getPortalSettings', summary: 'Get customer portal settings', description: 'Branding, domain and security policy of the customer portal of the current tenant.', security: [['cookieAuth' => []], ['bearerAuth' => []]], tags: ['Customer Portal'], responses: [new OA\Response(response: 200, description: 'Settings', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function show(): JsonResponse
    {
        return $this->successResponse(data: $this->present(PortalSetting::query()->first()), message: 'Portal settings retrieved successfully');
    }

    #[OA\Put(path: '/api/v1/crm/portal-settings', operationId: 'updatePortalSettings', summary: 'Update customer portal settings', description: 'Creates or updates the portal settings of the current tenant.', security: [['cookieAuth' => []], ['bearerAuth' => []]], tags: ['Customer Portal'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [new OA\Property(property: 'portal_slug', type: 'string', nullable: true), new OA\Property(property: 'custom_domain', type: 'string', nullable: true), new OA\Property(property: 'logo_url', type: 'string', nullable: true), new OA\Property(property: 'favicon_url', type: 'string', nullable: true), new OA\Property(property: 'primary_color', type: 'string', nullable: true), new OA\Property(property: 'secondary_color', type: 'string', nullable: true), new OA\Property(property: 'support_email', type: 'string', nullable: true), new OA\Property(property: 'privacy_policy_url', type: 'string', nullable: true), new OA\Property(property: 'require_otp', type: 'boolean'), new OA\Property(property: 'allow_partial_payments', type: 'boolean')])), responses: [new OA\Response(response: 200, description: 'Settings saved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')), new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function update(UpdatePortalSettingRequest $request): JsonResponse
    {
        $setting = PortalSetting::query()->firstOrNew([]);
        $setting->tenant_id = $this->tenantContext->getTenantId();
        $setting->fill($request->validated())->save();

        return $this->successResponse(data: $this->present($setting), message: 'Portal settings saved successfully');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(?PortalSetting $setting): array
    {
        return [
            'portal_slug' => $setting?->portal_slug,
            'custom_domain' => $setting?->custom_domain,
            'logo_url' => $setting?->logo_url,
            'favicon_url' => $setting?->favicon_url,
            'primary_color' => $setting?->primary_color,
            'secondary_color' => $setting?->secondary_color,
            'support_email' => $setting?->support_email,
            'privacy_policy_url' => $setting?->privacy_policy_url,
            'require_otp' => $setting->require_otp ?? false,
            'allow_partial_payments' => $setting->allow_partial_payments ?? false,
        ];
    }
}

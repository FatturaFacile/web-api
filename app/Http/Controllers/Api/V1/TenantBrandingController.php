<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Ui\UpdateTenantBranding;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ui\UpdateTenantBrandingRequest;
use App\Http\Resources\TenantBrandingResource;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class TenantBrandingController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TenantContext $tenantContext) {}

    #[OA\Get(
        path: '/api/v1/tenant/branding',
        operationId: 'getTenantBranding',
        description: 'Brand colours, logo and default colour scheme of the active tenant. Every member reads it: the client applies it on every page.',
        summary: 'Get tenant branding',
        security: [['bearerAuth' => []]],
        tags: ['Settings', 'Tenants'],
        responses: [
            new OA\Response(response: 200, description: 'Branding retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(): JsonResponse
    {
        return $this->successResponse(
            TenantBrandingResource::make(Tenant::findOrFail($this->tenantContext->getTenantId()))->resolve(),
            __('settings.messages.branding_retrieved')
        );
    }

    #[OA\Patch(
        path: '/api/v1/tenant/branding',
        operationId: 'updateTenantBranding',
        description: 'Partial update of the tenant branding: brand_primary_color and brand_secondary_color (#RRGGBB), brand_logo_url (https URL or storage path), default_theme (light, dark or system). Null clears a value. Requires the system module.',
        summary: 'Update tenant branding',
        security: [['bearerAuth' => []]],
        tags: ['Settings', 'Tenants'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'brand_primary_color', type: 'string', pattern: '^#[0-9A-Fa-f]{6}$', nullable: true, example: '#1F6FEB'),
                new OA\Property(property: 'brand_secondary_color', type: 'string', pattern: '^#[0-9A-Fa-f]{6}$', nullable: true, example: '#F59E0B'),
                new OA\Property(property: 'brand_logo_url', type: 'string', nullable: true, example: 'https://cdn.example.com/logo.svg'),
                new OA\Property(property: 'default_theme', type: 'string', enum: ['light', 'dark', 'system']),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Branding updated', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function update(UpdateTenantBrandingRequest $request, UpdateTenantBranding $action): JsonResponse
    {
        $tenant = $action->execute((string) $this->tenantContext->getTenantId(), $request->validated());
        $this->tenantContext->setTenant($tenant);

        return $this->successResponse(
            TenantBrandingResource::make($tenant)->resolve(),
            __('settings.messages.branding_updated')
        );
    }
}

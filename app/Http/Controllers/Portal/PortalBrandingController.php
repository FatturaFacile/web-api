<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Portal\PortalBrandingResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class PortalBrandingController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PortalBrandingResolver $branding) {}

    #[OA\Get(
        path: '/api/v1/portal/branding',
        operationId: 'getPortalBranding',
        description: 'White-label theme of the portal, resolved from the custom domain of the request or the `slug` query parameter, so the SPA can theme itself before sign-in. Only public presentation fields are returned.',
        summary: 'Get portal branding',
        security: [],
        tags: ['Customer Portal'],
        parameters: [
            new OA\Parameter(name: 'slug', in: 'query', required: false, description: 'Portal slug of the tenant', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Branding of the portal', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 404, description: 'No portal for this domain or slug', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $setting = $this->branding->resolve($request);

        abort_if($setting === null, 404, 'Portal not found.');

        $tenant = $setting->tenant()->withoutGlobalScopes()->first();

        return $this->successResponse(data: [
            'company_name' => $tenant?->name,
            'portal_slug' => $setting->portal_slug,
            'logo_url' => $setting->logo_url,
            'favicon_url' => $setting->favicon_url,
            'primary_color' => $setting->primary_color,
            'secondary_color' => $setting->secondary_color,
            'support_email' => $setting->support_email,
            'privacy_policy_url' => $setting->privacy_policy_url,
            'region' => $tenant?->country,
        ], message: 'Portal branding retrieved successfully');
    }
}

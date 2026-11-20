<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantMembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class MobileTenantController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TenantMembershipService $memberships) {}

    /** The companies the signed-in user can switch to (drives the mobile tenant switcher). */
    #[OA\Get(
        path: '/api/v1/mobile/tenants',
        operationId: 'getMobileTenants',
        description: 'Lists the tenants the authenticated user belongs to, so the mobile app can offer a company switcher.',
        summary: 'List the tenants of the signed-in user',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Mobile'],
        responses: [
            new OA\Response(response: 200, description: 'Tenants of the user', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        return $this->successResponse(
            data: $this->memberships->tenantsOf($request->user()),
            message: 'Tenants retrieved',
        );
    }
}

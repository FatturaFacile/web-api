<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Api\V1\Mobile\Concerns\ResolvesMobileContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Queries\Mobile\GetMobileBootstrapPayloadQuery;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class MobileBootstrapController extends Controller
{
    use ApiResponse, ResolvesMobileContext;

    /** Single call that hydrates the app after login: profile, workspaces, badges, sync plan. */
    #[OA\Get(
        path: '/api/v1/mobile/bootstrap',
        operationId: 'getMobileBootstrap',
        description: 'Single call that hydrates the mobile app after login: profile, workspaces, badges and sync plan.',
        summary: 'Get mobile bootstrap payload',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Mobile'],
        responses: [new OA\Response(response: 200, description: 'Bootstrap payload', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
    )]
    public function __invoke(Request $request, GetMobileBootstrapPayloadQuery $query, TenantContext $tenants): JsonResponse
    {
        return $this->successResponse(
            $query->handle($request->user(), $tenants->getTenant(), $this->currentDevice($request)),
            'Bootstrap payload'
        );
    }
}

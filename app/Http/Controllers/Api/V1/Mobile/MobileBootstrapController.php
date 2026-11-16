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

class MobileBootstrapController extends Controller
{
    use ApiResponse, ResolvesMobileContext;

    /** Single call that hydrates the app after login: profile, workspaces, badges, sync plan. */
    public function __invoke(Request $request, GetMobileBootstrapPayloadQuery $query, TenantContext $tenants): JsonResponse
    {
        return $this->successResponse(
            $query->handle($request->user(), $tenants->getTenant(), $this->currentDevice($request)),
            'Bootstrap payload'
        );
    }
}

<?php

declare(strict_types=1);

namespace Modules\CorporateGroup\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\CorporateGroup\Support\CorporatePermissions;

abstract class CorporateController extends Controller
{
    protected function tenantId(Request $request): string
    {
        return (string) app(TenantContext::class)->getTenantId();
    }

    protected function authorizeCompany(Request $request, string $permission, ?string $companyId): void
    {
        abort_unless(CorporatePermissions::allows($request->user(), $permission, $companyId), 403, 'Missing permission: '.$permission);
    }

    /**
     * Runs a domain action and turns a business-rule violation into a 422.
     *
     * @param  callable(): JsonResponse  $action
     */
    protected function guard(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}

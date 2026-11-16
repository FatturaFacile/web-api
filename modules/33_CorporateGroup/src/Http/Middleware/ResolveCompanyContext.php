<?php

declare(strict_types=1);

namespace Modules\CorporateGroup\Http\Middleware;

use App\Services\Tenancy\TenantContext;
use Closure;
use Enterprise\MultiTenant\Services\CompanyContext;
use Illuminate\Http\Request;
use Modules\CorporateGroup\Models\Company;
use Modules\CorporateGroup\Support\CorporatePermissions;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pins the request to one company (header `X-Company-Id`) and restricts what the user can see to the companies they
 * may reach. Must run after the `tenant` middleware.
 */
final class ResolveCompanyContext
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly TenantContext $tenantContext,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $allowed = CorporatePermissions::accessibleCompanyIds($user);
        $requested = (string) $request->header('X-Company-Id', '');

        if ($requested !== '') {
            $company = $this->tenantContext->hasTenant() ? Company::query()->find($requested) : null;
            if ($company === null) {
                return response()->json(['message' => 'Company not found.'], 404);
            }
            if ($allowed !== null && ! in_array($requested, $allowed, true)) {
                return response()->json(['message' => 'You have no access to this company.'], 403);
            }
            $this->companyContext->setActive($requested);
        } elseif ($allowed !== null) {
            $this->companyContext->setAllowed($allowed);
            if (count($allowed) === 1) {
                $this->companyContext->setActive($allowed[0]);
            }
        }

        try {
            return $next($request);
        } finally {
            $this->companyContext->clear();
        }
    }
}

<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Http\Middleware;

use App\Enums\Auth\AuthProblem;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Platform\Identity\Infrastructure\Models\RoleAssignment;
use Symfony\Component\HttpFoundation\Response;

/**
 * A request that names a company (X-Company-Id) is refused with 403 `company_access_revoked` when the member holds
 * role assignments limited to companies and none of them is that company (and none covers the whole tenant). Members
 * without scoped assignments keep the access their membership gives.
 */
final class EnsureCompanyAccess
{
    use ApiResponse;

    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $companyId = $request->header('X-Company-Id');
        $user = $request->user();
        $tenantId = $this->tenantContext->getTenantId();

        if (! is_string($companyId) || $companyId === '' || ! $user instanceof User || $tenantId === null) {
            return $next($request);
        }

        $assignments = RoleAssignment::query()->where('tenant_id', $tenantId)->where('user_id', $user->id)->pluck('company_id');
        $scoped = $assignments->filter(static fn (mixed $c): bool => $c !== null);

        if ($scoped->isEmpty() || $assignments->contains(null) || $scoped->contains($companyId)) {
            return $next($request);
        }

        return $this->denied();
    }

    private function denied(): JsonResponse
    {
        $response = $this->problemDetailsResponse('Company Access Revoked', 'You no longer have access to this company.', Response::HTTP_FORBIDDEN, AuthProblem::CompanyAccessRevoked->value);
        $response->setData([...(array) $response->getData(true), 'code' => AuthProblem::CompanyAccessRevoked->code()]);

        return $response;
    }
}

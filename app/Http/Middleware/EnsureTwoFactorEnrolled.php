<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Auth\AuthProblem;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Auth\Security\TenantSecurityPolicies;
use App\Services\Auth\TwoFactorAuthentication;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When the active tenant requires two factors of everyone, or of the role of the member (tenant security policy), a
 * member without them reaches only the authentication routes (profile,
 * logout, refresh, tenant switch and the two-factor setup) until the setup is complete. API keys are not users and
 * are not affected.
 */
final class EnsureTwoFactorEnrolled
{
    use ApiResponse;

    /**
     * Route names that stay open, so the member can complete the setup or leave.
     */
    private const ALLOWED_ROUTE_PREFIX = 'auth.';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TwoFactorAuthentication $twoFactor,
        private readonly TenantSecurityPolicies $policies,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || is_array($request->attributes->get('api_key_scopes'))) {
            return $next($request);
        }

        if (str_starts_with((string) $request->route()?->getName(), self::ALLOWED_ROUTE_PREFIX)) {
            return $next($request);
        }

        $tenant = $this->tenantContext->getTenant();
        $role = $tenant === null ? null : $user->getRoleInTenant((string) $tenant->id);

        if ($this->twoFactor->isEnabled($user) || ! $this->policies->requiresTwoFactor($tenant, $role)) {
            return $next($request);
        }

        return $this->problemDetailsResponse(
            title: __('two_factor.errors.setup_required_title'),
            detail: __('two_factor.errors.setup_required_detail'),
            status: Response::HTTP_FORBIDDEN,
            type: AuthProblem::TwoFactorSetupRequired->value,
        );
    }
}

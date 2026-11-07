<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Modules\Associations\Services\AreaTokenService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Member area endpoints: the personal token in the X-Area-Token header identifies the person and the tenant. It travels
 * in a header so that it stays out of URLs and access logs. While a restore replaces the data of the tenant, reads still
 * work and writes answer 503, as on the rest of the API.
 */
final class AuthenticateMemberArea
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AreaTokenService $tokens,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->header('X-Area-Token');

        if ($token === '') {
            return $this->problemDetailsResponse(title: 'Unauthenticated', detail: 'The X-Area-Token header is required.', status: Response::HTTP_UNAUTHORIZED);
        }

        $previous = $this->tenantContext->isExplicit() ? $this->tenantContext->getTenant() : null;

        try {
            $request->attributes->set('areaAccess', $this->tokens->access($token));

            if ($this->tenantContext->getTenant()?->is_maintenance && ! $request->isMethodSafe()) {
                return $this->problemDetailsResponse(title: __('backup.maintenance.title'), detail: __('backup.maintenance.detail'), status: Response::HTTP_SERVICE_UNAVAILABLE)
                    ->header('Retry-After', '60');
            }

            return $next($request);
        } finally {
            $previous === null ? $this->tenantContext->clear() : $this->tenantContext->setTenant($previous);
        }
    }
}

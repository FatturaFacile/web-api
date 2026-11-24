<?php

declare(strict_types=1);

namespace Modules\Childcare\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Modules\Childcare\Services\FamilyPortalService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Family portal endpoints: the personal token in the X-Family-Token header identifies the guardian and the tenant. It
 * travels in a header so that it stays out of URLs and access logs.
 */
final class AuthenticateFamilyPortal
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly FamilyPortalService $portal,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->header('X-Family-Token');

        if ($token === '') {
            return $this->problemDetailsResponse(title: 'Unauthenticated', detail: 'The X-Family-Token header is required.', status: Response::HTTP_UNAUTHORIZED);
        }

        $previous = $this->tenantContext->isExplicit() ? $this->tenantContext->getTenant() : null;

        try {
            try {
                $request->attributes->set('familyContact', $this->portal->contactFor($token));
            } catch (HttpExceptionInterface) {
                return $this->problemDetailsResponse(title: 'Unauthenticated', detail: 'The family token is not valid or has expired.', status: Response::HTTP_UNAUTHORIZED);
            }

            return $next($request);
        } finally {
            $previous === null ? $this->tenantContext->clear() : $this->tenantContext->setTenant($previous);
        }
    }
}

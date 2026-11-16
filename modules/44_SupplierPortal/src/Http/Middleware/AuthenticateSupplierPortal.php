<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Modules\SupplierPortal\Services\SupplierPortalAccessService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Supplier portal endpoints: the personal token in the X-Supplier-Token header identifies the supplier and the tenant. It
 * travels in a header so that it stays out of URLs and access logs.
 */
final class AuthenticateSupplierPortal
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly SupplierPortalAccessService $access,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->header('X-Supplier-Token');

        if ($token === '') {
            return $this->problemDetailsResponse(title: 'Unauthenticated', detail: 'The X-Supplier-Token header is required.', status: Response::HTTP_UNAUTHORIZED);
        }

        $previous = $this->tenantContext->isExplicit() ? $this->tenantContext->getTenant() : null;

        try {
            try {
                $request->attributes->set('portalSupplier', $this->access->supplierFor($token));
            } catch (HttpExceptionInterface) {
                return $this->problemDetailsResponse(title: 'Unauthenticated', detail: 'The supplier token is not valid or has expired.', status: Response::HTTP_UNAUTHORIZED);
            }

            return $next($request);
        } finally {
            $previous === null ? $this->tenantContext->clear() : $this->tenantContext->setTenant($previous);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IdentifyTenant
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $request->header('X-Tenant-ID');

        /** @var User|null $user */
        $user = $request->user();

        if (empty($tenantId) && $user !== null) {
            $tenantId = $user->tenants()->first()?->id;
        }

        if (empty($tenantId)) {
            return $this->problemDetailsResponse(
                title: 'Missing Tenant Context',
                detail: 'The X-Tenant-ID header is required to identify the active enterprise organization.',
                status: Response::HTTP_BAD_REQUEST,
            );
        }

        $tenant = Tenant::find($tenantId);

        if ($tenant === null) {
            return $this->problemDetailsResponse(
                title: 'Tenant Not Found',
                detail: "Tenant with ID '{$tenantId}' does not exist or has been disabled.",
                status: Response::HTTP_NOT_FOUND,
            );
        }

        if ($user !== null && ! $user->belongsToTenant($tenant->id)) {
            return $this->problemDetailsResponse(
                title: 'Forbidden Tenant Access',
                detail: 'Authenticated user is not authorized to access records belonging to this tenant.',
                status: Response::HTTP_FORBIDDEN,
            );
        }

        // A restore from backup is replacing the data of the tenant: reads are fine, writes have to wait.
        if ($tenant->is_maintenance && ! $request->isMethodSafe()) {
            return $this->problemDetailsResponse(
                title: __('backup.maintenance.title'),
                detail: __('backup.maintenance.detail'),
                status: Response::HTTP_SERVICE_UNAVAILABLE,
            )->header('Retry-After', '60');
        }

        $this->tenantContext->setTenant($tenant);

        try {
            return $next($request);
        } finally {
            $this->tenantContext->clear();
        }
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->tenantContext->clear();
    }
}

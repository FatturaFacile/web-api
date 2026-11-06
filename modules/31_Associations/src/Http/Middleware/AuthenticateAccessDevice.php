<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Modules\Associations\Services\AccessDeviceService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reader endpoints: the device authenticates itself, and its row decides the tenant.
 */
final class AuthenticateAccessDevice
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AccessDeviceService $devices,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $device = $this->devices->authenticate((string) $request->header('X-Device-Key'), (string) $request->header('X-Device-Secret'));
        $tenant = $device === null ? null : Tenant::query()->whereKey($device->tenant_id)->where('status', 'active')->first();

        if ($device === null || $tenant === null) {
            return $this->problemDetailsResponse(title: 'Unauthenticated', detail: 'Unknown device or wrong secret.', status: Response::HTTP_UNAUTHORIZED);
        }

        $this->tenantContext->setTenant($tenant);
        $request->attributes->set('accessDevice', $device);

        try {
            return $next($request);
        } finally {
            $this->tenantContext->clear();
        }
    }
}

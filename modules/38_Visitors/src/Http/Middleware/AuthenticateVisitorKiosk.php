<?php

declare(strict_types=1);

namespace Modules\Visitors\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Modules\Visitors\Models\VisitorKiosk;
use Modules\Visitors\Services\VisitorKioskService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Kiosk endpoints: the tablet authenticates with X-Kiosk-Key and X-Kiosk-Secret, and its row decides the tenant. While
 * a restore replaces the data of the tenant, reads still work and writes answer 503, as on the rest of the API.
 */
final class AuthenticateVisitorKiosk
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly VisitorKioskService $kiosks,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $kiosk = $this->kiosks->authenticate((string) $request->header('X-Kiosk-Key'), (string) $request->header('X-Kiosk-Secret'));
        $tenant = $kiosk === null ? null : Tenant::query()->whereKey($kiosk->tenant_id)->where('status', 'active')->first();

        if ($kiosk === null || $tenant === null) {
            return $this->problemDetailsResponse(title: 'Unauthenticated', detail: 'Unknown kiosk or wrong secret.', status: Response::HTTP_UNAUTHORIZED);
        }

        if ($tenant->is_maintenance && ! $request->isMethodSafe()) {
            return $this->problemDetailsResponse(title: __('backup.maintenance.title'), detail: __('backup.maintenance.detail'), status: Response::HTTP_SERVICE_UNAVAILABLE)
                ->header('Retry-After', '60');
        }

        $previous = $this->tenantContext->isExplicit() ? $this->tenantContext->getTenant() : null;
        $this->tenantContext->setTenant($tenant);
        $request->attributes->set('visitorKiosk', $kiosk);

        if (! $tenant->is_maintenance && ($kiosk->last_seen_at === null || $kiosk->last_seen_at->lt(now()->subMinute()))) {
            VisitorKiosk::query()->withoutGlobalScopes()->whereKey($kiosk->id)->update(['last_seen_at' => now()]);
        }

        try {
            return $next($request);
        } finally {
            $previous === null ? $this->tenantContext->clear() : $this->tenantContext->setTenant($previous);
        }
    }
}

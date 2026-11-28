<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Modules\SkiOutdoorSchool\Models\SchoolSetting;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public booking routes: the slug of the school is the only thing that selects the company, and only when its owner switched the portal on.
 * A company id sent by the client is never read. An unknown slug and a disabled portal answer the same 404.
 */
final class ResolvePublicSchool
{
    use ApiResponse;

    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $settings = SchoolSetting::withoutGlobalScopes()->whereNull('deleted_at')->where('public_slug', (string) $request->route('slug'))->where('booking_enabled', true)->first();
        $tenant = $settings === null ? null : Tenant::query()->whereKey($settings->tenant_id)->where('status', 'active')->first();

        if ($tenant === null) {
            return $this->problemDetailsResponse(title: 'Not Found', detail: 'This booking page does not exist.', status: Response::HTTP_NOT_FOUND);
        }

        if ($tenant->is_maintenance) {
            return $this->problemDetailsResponse(title: __('backup.maintenance.title'), detail: __('backup.maintenance.detail'), status: Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $previous = $this->tenantContext->isExplicit() ? $this->tenantContext->getTenant() : null;
        $this->tenantContext->setTenant($tenant);

        try {
            return $next($request);
        } finally {
            $previous === null ? $this->tenantContext->clear() : $this->tenantContext->setTenant($previous);
        }
    }
}

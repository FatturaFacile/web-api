<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Presentation\Http\Middleware;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the tenant of the public QR allergen book from the `{tenantId}` route parameter. It only runs behind the `signed`
 * middleware, so the tenant id is covered by the URL signature the laboratory generated; the context is always cleared after.
 */
final readonly class ResolveGelatoPublicTenant
{
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Tenant::query()->find((string) $request->route('tenantId'));

        if ($tenant === null || ($tenant->status ?? 'active') !== 'active') {
            return response()->json(['message' => 'Allergen book not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->tenantContext->setTenant($tenant);

        try {
            return $next($request);
        } finally {
            $this->tenantContext->clear();
        }
    }
}

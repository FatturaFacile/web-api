<?php

declare(strict_types=1);

namespace Modules\Integrations\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Modules\Integrations\Models\ApiKey;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates machine access with a tenant API key. The key fixes the tenant, so no tenant header is read from the
 * caller, and its scopes reach the module check through the request: module.access still decides.
 */
final class AuthenticateApiKey
{
    use ApiResponse;

    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) ($request->bearerToken() ?: $request->header('X-Api-Key'));
        $key = str_starts_with($token, ApiKey::TokenPrefix)
            ? ApiKey::withoutGlobalScopes()->with('tenant')->where('key_hash', hash('sha256', $token))->first()
            : null;

        if ($key === null || ! $key->isUsable() || $key->tenant === null) {
            return $this->problemDetailsResponse(title: 'Invalid API Key', detail: 'The API key is missing, revoked or expired.', status: Response::HTTP_UNAUTHORIZED);
        }

        if ($key->tenant->is_maintenance && ! $request->isMethodSafe()) {
            return $this->problemDetailsResponse(title: __('backup.maintenance.title'), detail: __('backup.maintenance.detail'), status: Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if ($key->last_used_at === null || $key->last_used_at->lt(now()->subMinute())) {
            ApiKey::withoutGlobalScopes()->whereKey($key->id)->update(['last_used_at' => now()]);
        }

        $request->attributes->set('api_key_scopes', $key->scopes ?? []);
        $this->tenantContext->setTenant($key->tenant);

        try {
            return $next($request);
        } finally {
            $this->tenantContext->clear();
        }
    }
}

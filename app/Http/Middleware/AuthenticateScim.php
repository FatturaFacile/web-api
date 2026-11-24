<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\Auth\Scim\ScimTokens;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a SCIM 2.0 request (RFC 7644 section 2) by the bearer token of a tenant. The token identifies the tenant:
 * the request runs in that tenant's context and in no other, so the tenant model scopes apply to everything it touches.
 * A user token of the application is not accepted here, and a SCIM token opens nothing outside the SCIM routes.
 */
final class AuthenticateScim
{
    /**
     * Seconds between two writes of the last use of a token.
     */
    private const LAST_USED_WRITE_INTERVAL = 60;

    public function __construct(
        private readonly ScimTokens $tokens,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();
        $token = $plain === null || $plain === '' ? null : $this->tokens->find($plain);

        if ($token === null) {
            return $this->error(Response::HTTP_UNAUTHORIZED, 'Invalid or missing bearer token.')->header('WWW-Authenticate', 'Bearer');
        }

        $tenant = Tenant::query()->find($token->tenant_id);

        if ($tenant === null || ! in_array($tenant->status, [null, 'active'], true)) {
            return $this->error(Response::HTTP_FORBIDDEN, 'The organization is not active.');
        }

        if ($token->last_used_at === null || $token->last_used_at->diffInSeconds(now(), true) >= self::LAST_USED_WRITE_INTERVAL) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->attributes->set('scim_token_id', (string) $token->id);
        $this->tenantContext->setTenant($tenant);

        try {
            return $next($request);
        } finally {
            $this->tenantContext->clear();
        }
    }

    private function error(int $status, string $detail): JsonResponse
    {
        return response()->json([
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'],
            'status' => (string) $status,
            'detail' => $detail,
        ], $status, ['Content-Type' => 'application/scim+json']);
    }
}

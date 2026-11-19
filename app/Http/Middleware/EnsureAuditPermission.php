<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RBAC gate of the audit trail endpoints: `audit.permission:audit.view`. The audit trail is read-only for everyone, so
 * the permissions only decide who may read, verify, export and manage the WORM archive.
 */
final class EnsureAuditPermission
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        $tenantId = $this->tenantContext->getTenantId() ?? ($user instanceof User ? $user->tenant_id : null);

        if (! $user instanceof User || ! $user->hasTenantPermission($tenantId === null ? null : (string) $tenantId, $permission)) {
            return response()->json([
                'type' => 'https://httpstatuses.com/403',
                'title' => 'Forbidden',
                'status' => 403,
                'detail' => "The permission {$permission} is required.",
                'instance' => $request->getRequestUri(),
            ], 403);
        }

        return $next($request);
    }
}

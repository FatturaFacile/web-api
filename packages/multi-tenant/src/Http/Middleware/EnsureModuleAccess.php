<?php

declare(strict_types=1);

namespace Enterprise\MultiTenant\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Closure;
use Enterprise\MultiTenant\Services\ModuleAccessGate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Checks the role of the user in the active tenant against config/permissions.php. It runs on the routes of a module,
 * so a controller or a policy that forgets a check cannot expose an action.
 */
final class EnsureModuleAccess
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ModuleAccessGate $gate,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     * @param  string|null  $access  "read" for a POST that saves nothing (a simulation, a preview), as in
     *                               module.access:payroll,read; by default the HTTP method decides
     */
    public function handle(Request $request, Closure $next, string $module, ?string $access = null): Response
    {
        $access = in_array($access, ['read', 'write'], true) ? $access : ($request->isMethodSafe() ? 'read' : 'write');

        $apiKeyScopes = $request->attributes->get('api_key_scopes');

        if (is_array($apiKeyScopes)) {
            return in_array("{$module}.{$access}", $apiKeyScopes, true) ? $next($request) : $this->problemDetailsResponse(
                title: 'Forbidden Module Access',
                detail: "This API key has no {$access} scope on the {$module} module.",
                status: Response::HTTP_FORBIDDEN,
            );
        }

        /** @var User|null $user */
        $user = $request->user();

        if ($this->gate->allows($user, $this->tenantContext->getTenantId(), $module, $access)) {
            return $next($request);
        }

        return $this->problemDetailsResponse(
            title: 'Forbidden Module Access',
            detail: "Your role does not allow {$access} access to the {$module} module.",
            status: Response::HTTP_FORBIDDEN,
        );
    }
}

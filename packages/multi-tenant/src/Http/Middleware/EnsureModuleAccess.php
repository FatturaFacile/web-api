<?php

declare(strict_types=1);

namespace Enterprise\MultiTenant\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Checks the role of the user in the active tenant against config/permissions.php. It runs on the routes of a module,
 * so a controller or a policy that forgets a check cannot expose an action.
 */
final class EnsureModuleAccess
{
    use ApiResponse;

    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $access = $request->isMethodSafe() ? 'read' : 'write';
        $allowedRoles = config("permissions.modules.{$module}.{$access}");

        if (! is_array($allowedRoles)) {
            throw new InvalidArgumentException("Unknown module [{$module}] in the access configuration.");
        }

        /** @var User|null $user */
        $user = $request->user();
        $tenantId = $this->tenantContext->getTenantId();
        $membership = $user !== null && $tenantId !== null ? $user->tenants()->where('tenants.id', $tenantId)->first()?->pivot : null;

        if ($membership !== null && $this->isAllowed((string) $membership->role, $membership->permissions, $allowedRoles, "{$module}.{$access}")) {
            return $next($request);
        }

        return $this->problemDetailsResponse(
            title: 'Forbidden Module Access',
            detail: "Your role does not allow {$access} access to the {$module} module.",
            status: Response::HTTP_FORBIDDEN,
        );
    }

    /**
     * @param  array<int, string>  $allowedRoles
     */
    private function isAllowed(string $role, mixed $permissions, array $allowedRoles, string $permission): bool
    {
        if (in_array('*', $allowedRoles, true) || in_array($role, $allowedRoles, true)) {
            return true;
        }

        $granted = is_string($permissions) ? json_decode($permissions, true) : $permissions;

        return is_array($granted) && (in_array($permission, $granted, true) || in_array('*', $granted, true));
    }
}

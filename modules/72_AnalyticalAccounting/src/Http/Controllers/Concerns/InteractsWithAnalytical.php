<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Http\Controllers\Concerns;

use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Tenant, user, pagination and domain-error mapping of the request for the analytical accounting controllers.
 */
trait InteractsWithAnalytical
{
    private function tenantId(): string
    {
        $tenantId = app(TenantContext::class)->getTenantId() ?? request()->user()?->tenant_id;
        abort_if($tenantId === null, 403, 'Missing or unresolvable tenant context');

        return (string) $tenantId;
    }

    private function userId(): ?string
    {
        $id = request()->user()?->getAuthIdentifier();

        return $id === null ? null : (string) $id;
    }

    private function perPage(): int
    {
        return max(1, min(100, (int) request()->query('per_page', 25)));
    }

    /**
     * Run a domain operation; a rule violation becomes a 422 validation error.
     *
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    private function domain(Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (RuntimeException|InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['request' => [$exception->getMessage()]]);
        }
    }
}

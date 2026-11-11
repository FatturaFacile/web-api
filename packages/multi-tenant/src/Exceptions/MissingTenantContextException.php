<?php

declare(strict_types=1);

namespace Enterprise\MultiTenant\Exceptions;

use RuntimeException;

/**
 * A tenant record was about to be stored without a tenant: there is no tenant context, no explicit tenant_id and the
 * record was not declared global. Storing it with a null tenant_id would make it visible to every tenant.
 */
final class MissingTenantContextException extends RuntimeException
{
    public static function forModel(string $model): self
    {
        return new self(sprintf(
            'Cannot create %s without a tenant: set the tenant context (TenantContext::runWithTenant), pass an explicit tenant_id, '
            .'or, only for shared data, declare it global (the model must use AllowsGlobalRecords and be written with createGlobal() or TenantContext::runAsGlobal()).',
            $model,
        ));
    }

    public static function modelDoesNotAllowGlobal(string $model): self
    {
        return new self(sprintf('%s does not allow global records (tenant_id null): add the AllowsGlobalRecords trait only if its rows are shared by every tenant.', $model));
    }
}

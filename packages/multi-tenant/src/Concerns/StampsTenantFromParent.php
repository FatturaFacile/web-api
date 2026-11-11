<?php

declare(strict_types=1);

namespace Enterprise\MultiTenant\Concerns;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantSchemaPolicy;
use Enterprise\MultiTenant\Exceptions\MissingTenantContextException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * For the child tables of a document (invoice lines, order items, ledger lines ...): the row always carries the tenant
 * of its parent, taken from the parent on creation. The parent table and foreign key are declared in
 * TenantSchemaPolicy::CHILD_TABLES. The model keeps no global scope: its rows are read through the scoped parent.
 *
 * A child of a global parent (a null tenant, for example the installments of a shared payment term) is global too. A
 * child never gets a null tenant by accident: without a parent, an explicit tenant_id or a tenant context the creation
 * throws MissingTenantContextException.
 */
trait StampsTenantFromParent
{
    public static function bootStampsTenantFromParent(): void
    {
        static::creating(static fn (Model $model) => static::stampTenantFromParent($model));
    }

    /**
     * @throws MissingTenantContextException
     * @throws AccessDeniedHttpException
     */
    protected static function stampTenantFromParent(Model $model): void
    {
        $tenantContext = app(TenantContext::class);
        $parent = static::lookupParentTenant($model);

        if ($tenantContext->isExplicit()) {
            $user = auth()->user();
            $platformAdmin = $user instanceof User && (bool) $user->is_platform_admin;

            if ($parent !== null && $parent['tenant_id'] === null) {
                if (! $platformAdmin) {
                    throw new AccessDeniedHttpException('Generic records are read-only for tenant users.');
                }

                $model->setAttribute('tenant_id', null);

                return;
            }

            if ($parent !== null && $parent['tenant_id'] !== $tenantContext->getTenantId()) {
                throw new AccessDeniedHttpException('The parent record belongs to another tenant.');
            }

            $model->setAttribute('tenant_id', $tenantContext->getTenantId());

            return;
        }

        if (! empty($model->getAttribute('tenant_id'))) {
            return;
        }

        if ($parent !== null) {
            $model->setAttribute('tenant_id', $parent['tenant_id']);

            return;
        }

        if ($tenantContext->hasTenant()) {
            $model->setAttribute('tenant_id', $tenantContext->getTenantId());

            return;
        }

        throw MissingTenantContextException::forModel($model::class);
    }

    /**
     * @return array{tenant_id: ?string}|null null when the parent is not declared or not found
     */
    protected static function lookupParentTenant(Model $model): ?array
    {
        $definition = TenantSchemaPolicy::parentOf($model->getTable());

        if ($definition === null || empty($model->getAttribute($definition[1]))) {
            return null;
        }

        $row = DB::table($definition[0])->where('id', $model->getAttribute($definition[1]))->first(['tenant_id']);

        return $row === null ? null : ['tenant_id' => $row->tenant_id !== null ? (string) $row->tenant_id : null];
    }
}

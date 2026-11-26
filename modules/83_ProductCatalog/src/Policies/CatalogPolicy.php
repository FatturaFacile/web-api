<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Policies;

use App\Models\User;
use App\Policies\Concerns\ResolvesTenantMembership;

/**
 * Who reads and writes the catalog is decided by the module access rules (config/permissions.php, module "product_catalog").
 * The gate keeps every call inside a company the user belongs to; records of other companies are invisible through the
 * TenantScope of the models.
 */
final class CatalogPolicy
{
    use ResolvesTenantMembership;

    public const string ABILITY = 'product-catalog.access';

    public function access(User $user): bool
    {
        return $this->isMember($user);
    }
}

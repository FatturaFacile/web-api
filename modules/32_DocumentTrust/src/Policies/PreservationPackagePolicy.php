<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Policies;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Auth\Access\Response;
use Modules\DocumentTrust\Models\PreservationPackage;

/**
 * Who may do what with a preservation package. The module middleware already splits readers and writers; this policy
 * adds the immutability of a closed package and reserves settings and the access log to owners and administrators.
 */
final readonly class PreservationPackagePolicy
{
    /**
     * Roles that edit the retention settings and read the access log.
     *
     * @var list<string>
     */
    private const array Managers = ['owner', 'admin'];

    /**
     * Inject the tenant context.
     */
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * Changing an open package (adding or removing files). A closed package is immutable.
     */
    public function update(User $user, PreservationPackage $package): Response
    {
        return $package->isClosed() ? Response::denyWithStatus(409, 'A closed preservation package is immutable.') : Response::allow();
    }

    /**
     * Deleting a package is possible only while it is open.
     */
    public function delete(User $user, PreservationPackage $package): Response
    {
        return $package->isClosed() ? Response::denyWithStatus(409, 'A closed preservation package cannot be deleted.') : Response::allow();
    }

    /**
     * Closing an open package.
     */
    public function close(User $user, PreservationPackage $package): Response
    {
        return $package->isClosed() ? Response::denyWithStatus(409, 'The package is already closed.') : Response::allow();
    }

    /**
     * Retention settings and access log: owners and administrators.
     */
    public function manage(User $user): bool
    {
        return in_array((string) $user->getRoleInTenant((string) $this->tenantContext->getTenantId()), self::Managers, true);
    }
}

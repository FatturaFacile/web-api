<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Infrastructure\Services;

use App\Models\User;
use Modules\KpiDashboards\Domain\Enums\KpiCode;
use Modules\KpiDashboards\Domain\Enums\RoleCode;
use Modules\KpiDashboards\Domain\ValueObjects\CockpitFilter;

/**
 * Role-based and attribute-based access of a cockpit (Mod 004).
 *
 * RBAC: a cockpit opens for the membership roles of RoleCode::allowedMembershipRoles() or a `kpi.cockpit.<role>` /
 * `kpi.*` permission on the membership. ABAC: `kpi.entity:<company id>` and `kpi.cost_center:<id>` permissions
 * restrict the legal entities and cost centers a member may aggregate; without any, the member sees all of them.
 * Field masking: compensation figures need `kpi.hr.compensation` (owners, admins and HR managers have it).
 */
final class KpiAccess
{
    /**
     * @param  list<string>  $permissions
     */
    public function __construct(
        private readonly string $role,
        private readonly array $permissions,
    ) {}

    public static function forUser(?User $user, string $tenantId): self
    {
        $pivot = $user?->tenants()->where('tenants.id', $tenantId)->first()?->pivot;
        $permissions = $pivot?->permissions;
        $permissions = is_string($permissions) ? json_decode($permissions, true) : $permissions;

        return new self((string) ($pivot->role ?? ''), is_array($permissions) ? array_values(array_map('strval', $permissions)) : []);
    }

    /**
     * Digest of what the viewer's role and grants change in a payload, so a cached payload is never served to a viewer who
     * would see different (masked or restricted) figures.
     */
    public function fingerprint(): string
    {
        $permissions = array_values(array_filter($this->permissions, static fn (string $permission): bool => str_starts_with($permission, 'kpi.') || str_starts_with($permission, 'deny:kpi')));
        sort($permissions);

        return hash('xxh128', $this->role.'|'.implode(',', $permissions));
    }

    public function canOpen(RoleCode $role): bool
    {
        if ($this->role === '') {
            return false;
        }

        if ($this->hasPermission('deny:kpi_dashboards')) {
            return false;
        }

        $allowed = $role->allowedMembershipRoles();

        return in_array('*', $allowed, true)
            || in_array($this->role, $allowed, true)
            || $this->hasPermission("kpi.cockpit.{$role->value}")
            || $this->hasPermission('kpi.*');
    }

    public function canSeeCompensation(): bool
    {
        return in_array($this->role, ['owner', 'admin', 'hr_manager'], true) || $this->hasPermission('kpi.hr.compensation') || $this->hasPermission('kpi.*');
    }

    public function canSeeKpi(KpiCode $kpi): bool
    {
        return $this->canOpen($kpi->role());
    }

    /**
     * The filter narrowed to the legal entities and cost centers the member may see.
     */
    public function restrict(CockpitFilter $filter, bool $consolidatedElimination): CockpitFilter
    {
        $companies = $this->restricted($filter->companyIds, 'kpi.entity:');
        $costCenters = $this->restricted($filter->costCenterIds, 'kpi.cost_center:');

        return new CockpitFilter(
            $filter->tenantId, $filter->from, $filter->to, $filter->comparison, $companies, $costCenters, $filter->displayCurrency, $consolidatedElimination,
        );
    }

    /**
     * @param  list<string>  $requested
     * @return list<string>
     */
    private function restricted(array $requested, string $prefix): array
    {
        $granted = [];

        foreach ($this->permissions as $permission) {
            if (str_starts_with($permission, $prefix)) {
                $granted[] = substr($permission, strlen($prefix));
            }
        }

        if ($granted === [] || $this->role === 'owner') {
            return $requested;
        }

        // Asking for nothing means "everything I may see"; asking for something outside the grant yields nothing at all.
        if ($requested === []) {
            return $granted;
        }

        $allowed = array_values(array_intersect($requested, $granted));

        return $allowed !== [] ? $allowed : ['00000000-0000-0000-0000-000000000000'];
    }

    private function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }
}

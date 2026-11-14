<?php

declare(strict_types=1);

namespace Modules\Governance\Services;

use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * The members of the active company (id, name, role), loaded once per request to show owners and auditors by name and
 * to fill the owner pickers of the screens.
 */
final class GovernanceMembers
{
    /** @var array<string, array<string, array{id: string, name: string, role: string}>> */
    private array $byTenant = [];

    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @return list<array{id: string, name: string, role: string}>
     */
    public function all(): array
    {
        return array_values($this->load(true));
    }

    /**
     * @return array{id: string, name: string}|null
     */
    public function reference(?string $userId): ?array
    {
        if ($userId === null) {
            return null;
        }

        $member = $this->load(false)[$userId] ?? null;

        return $member === null ? ['id' => $userId, 'name' => ''] : ['id' => $member['id'], 'name' => $member['name']];
    }

    /**
     * @return array<string, array{id: string, name: string, role: string}>
     */
    private function load(bool $fresh): array
    {
        $tenantId = (string) $this->tenantContext->getTenantId();

        if ($fresh || ! isset($this->byTenant[$tenantId])) {
            $this->byTenant[$tenantId] = DB::table('tenant_user')
                ->join('users', 'users.id', '=', 'tenant_user.user_id')
                ->where('tenant_user.tenant_id', $tenantId)
                ->orderBy('users.name')
                ->get(['users.id', 'users.name', 'tenant_user.role'])
                ->mapWithKeys(static fn (object $row): array => [(string) $row->id => ['id' => (string) $row->id, 'name' => (string) $row->name, 'role' => (string) $row->role]])
                ->all();
        }

        return $this->byTenant[$tenantId];
    }
}

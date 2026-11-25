<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The people of a company the satisfaction screens offer as owners of a case, and the support lead the closed-loop alerts fall back on.
 */
final class TeamDirectory
{
    /**
     * Active members of the company.
     *
     * @return list<array{id: string, name: string}>
     */
    public function members(string $tenantId): array
    {
        return User::query()
            ->whereIn('id', DB::table('tenant_user')->where('tenant_id', $tenantId)->whereNull('suspended_at')->select('user_id'))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (User $user): array => ['id' => (string) $user->id, 'name' => (string) $user->name])
            ->all();
    }

    /**
     * The longest-standing active member holding one of the support lead roles (`customer_satisfaction.support_lead_roles`).
     */
    public function supportLeadId(string $tenantId): ?string
    {
        $id = DB::table('tenant_user')
            ->where('tenant_id', $tenantId)
            ->whereIn('role', (array) config('customer_satisfaction.support_lead_roles'))
            ->whereNull('suspended_at')
            ->orderBy('created_at')
            ->value('user_id');

        return is_string($id) ? $id : null;
    }
}

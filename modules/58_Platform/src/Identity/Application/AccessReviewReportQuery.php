<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Application;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Access review for audits (CH/IT): every member of the tenant with the roles, the last sign-in and an inactivity flag. */
final class AccessReviewReportQuery
{
    public const int INACTIVE_AFTER_DAYS = 90;

    /**
     * @return list<array{user_id: string, name: string, email: string, role: string, assigned_roles: list<string>, last_login_at: ?string, inactive: bool}>
     */
    public function handle(string $tenantId, int $inactiveAfterDays = self::INACTIVE_AFTER_DAYS): array
    {
        $members = DB::table('tenant_user')
            ->join('users', 'users.id', '=', 'tenant_user.user_id')
            ->where('tenant_user.tenant_id', $tenantId)
            ->whereNull('tenant_user.deleted_at')
            ->orderBy('users.email')
            ->get(['users.id', 'users.name', 'users.email', 'tenant_user.role']);

        $lastUsed = DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->whereIn('tokenable_id', $members->pluck('id')->all())
            ->groupBy('tokenable_id')
            ->selectRaw('tokenable_id, MAX(last_used_at) as last_used')
            ->pluck('last_used', 'tokenable_id');

        $assigned = DB::table('role_assignments')
            ->join('roles', 'roles.id', '=', 'role_assignments.role_id')
            ->where('role_assignments.tenant_id', $tenantId)
            ->get(['role_assignments.user_id', 'roles.name'])
            ->groupBy('user_id')
            ->map(static fn ($rows): array => $rows->pluck('name')->map(static fn (mixed $n): string => (string) $n)->values()->all());

        $threshold = now()->subDays($inactiveAfterDays);

        return $members->map(static function (object $m) use ($lastUsed, $assigned, $threshold): array {
            $last = $lastUsed[$m->id] ?? null;

            return [
                'user_id' => (string) $m->id,
                'name' => (string) $m->name,
                'email' => (string) $m->email,
                'role' => (string) $m->role,
                'assigned_roles' => $assigned[$m->id] ?? [],
                'last_login_at' => $last !== null ? (string) $last : null,
                'inactive' => $last === null || Carbon::parse((string) $last)->lt($threshold),
            ];
        })->values()->all();
    }
}

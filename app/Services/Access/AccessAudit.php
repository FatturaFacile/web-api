<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Records every change of users, roles, teams, delegations and invitations in the audit log of the tenant.
 */
final class AccessAudit
{
    public const string TypeMember = 'access.member';

    public const string TypeRole = 'access.role';

    public const string TypeTeam = 'access.team';

    public const string TypeDelegation = 'access.delegation';

    public const string TypeInvitation = 'access.invitation';

    public const string TypeImport = 'access.import';

    public const string TypePlan = 'access.plan';

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return [self::TypeMember, self::TypeRole, self::TypeTeam, self::TypeDelegation, self::TypeInvitation, self::TypeImport, self::TypePlan];
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public static function record(string $tenantId, string $type, ?string $subjectId, string $event, ?array $old = null, ?array $new = null): void
    {
        $request = request();

        AuditLog::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'user_id' => Auth::id(),
            'event' => $event,
            'auditable_type' => $type,
            'auditable_id' => $subjectId ?? (string) Str::uuid(),
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Auth\Security;

use App\Models\User;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

/**
 * The read side of the security audit viewer: the authentication and security events (see SecurityAuditCatalog) of one
 * tenant on the audit ledger, newest first, with the filters of the API. The ledger is never written here.
 *
 * Status and auth method of an entry are read from its details (see RegistrationAudit); entries recorded before the
 * standard keys existed, and the IAM engine events, fall back to what their action and details imply.
 */
final class SecurityAuditReader
{
    /**
     * @param  array<string, mixed>  $filters  from, to, user_id, event_type (list or comma separated), status, auth_method
     * @return LengthAwarePaginator<int, AuditLedgerEntry>
     */
    public function paginate(string $tenantId, array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->query($tenantId, $filters)
            ->orderByDesc('event_timestamp')->orderByDesc('id')
            ->paginate(max(5, min($perPage, 100)));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<AuditLedgerEntry>
     */
    public function query(string $tenantId, array $filters): Builder
    {
        $query = AuditLedgerEntry::query()
            ->where('tenant_id', $tenantId)
            ->where(function (Builder $scope): void {
                $scope->where('module_code', SecurityAuditCatalog::AUTH_MODULE)
                    ->orWhere(function (Builder $access): void {
                        $access->where('module_code', SecurityAuditCatalog::ACCESS_MODULE)
                            ->where('action', 'like', SecurityAuditCatalog::IDENTITY_PREFIX.'%');
                    });
            });

        if (! empty($filters['from'])) {
            $query->where('event_timestamp', '>=', Carbon::parse((string) $filters['from'], 'UTC')->startOfDay()->setTimezone('UTC')->format('Y-m-d H:i:s.u'));
        }

        if (! empty($filters['to'])) {
            $to = Carbon::parse((string) $filters['to'], 'UTC');
            $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $filters['to']) === 1 ? $to->endOfDay() : $to;
            $query->where('event_timestamp', '<=', $to->setTimezone('UTC')->format('Y-m-d H:i:s.u'));
        }

        if (! empty($filters['user_id'])) {
            $userId = (string) $filters['user_id'];
            $query->where(fn (Builder $who) => $who->where('resource_id', $userId)->orWhere('payload_after->user_id', $userId));
        }

        $types = $filters['event_type'] ?? null;
        $types = is_string($types) ? explode(',', $types) : $types;

        if (is_array($types) && $types !== []) {
            $query->whereIn('action', array_map(static fn (mixed $type): string => strtoupper(trim((string) $type)), $types));
        }

        if (! empty($filters['status'])) {
            $query->whereIn('action', SecurityAuditCatalog::typesWithStatus((string) $filters['status']));
        }

        if (! empty($filters['auth_method'])) {
            $this->filterAuthMethod($query, (string) $filters['auth_method']);
        }

        return $query;
    }

    /**
     * The accounts the entries of a page are about, by id (accounts that were erased are absent).
     *
     * @param  iterable<AuditLedgerEntry>  $entries
     * @return array<string, User>
     */
    public function usersOf(iterable $entries): array
    {
        $ids = [];

        foreach ($entries as $entry) {
            $id = self::userIdOf($entry);

            if ($id !== null) {
                $ids[$id] = true;
            }
        }

        return $ids === [] ? [] : User::withTrashed()->whereKey(array_keys($ids))->get()->keyBy(fn (User $user): string => (string) $user->id)->all();
    }

    /**
     * The user an entry is about: the `user_id` detail, else the resource of an IAM engine event (a real user), else
     * unknown (older entries without a user have a random resource id).
     */
    public static function userIdOf(AuditLedgerEntry $entry): ?string
    {
        $details = (array) $entry->payload_after;

        if (isset($details['user_id']) && is_string($details['user_id'])) {
            return $details['user_id'];
        }

        return str_starts_with(strtoupper((string) $entry->action), SecurityAuditCatalog::IDENTITY_PREFIX) ? (string) $entry->resource_id : null;
    }

    /**
     * @param  Builder<AuditLedgerEntry>  $query
     */
    private function filterAuthMethod(Builder $query, string $method): void
    {
        $query->where(function (Builder $scope) use ($method): void {
            $scope->where('payload_after->auth_method', $method)->orWhere('payload_after->authMethod', $method);

            // SSO entries recorded before `auth_method` existed carry the protocol.
            if (in_array($method, ['sso_saml', 'sso_oidc'], true)) {
                $scope->orWhere(function (Builder $legacy) use ($method): void {
                    $legacy->where('action', 'like', 'SSO_%')->where('payload_after->protocol', $method === 'sso_saml' ? 'saml' : 'oidc');
                });
            }
        });
    }
}

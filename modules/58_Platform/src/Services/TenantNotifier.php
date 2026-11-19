<?php

declare(strict_types=1);

namespace Modules\Platform\Services;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Sends a business notification to the people of a tenant through the NotificationDispatcher, so preferences, quiet
 * hours, snooze, digest and the delivery register always apply. Used by the event listeners and the alert rules.
 */
final class TenantNotifier
{
    private const array DefaultRecipientRoles = ['owner', 'admin'];

    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    /**
     * Owners and administrators of the tenant, or the given members only (ids of other tenants are ignored).
     *
     * @param  list<string>|null  $userIds
     * @return Collection<int, User>
     */
    public function recipients(string $tenantId, ?array $userIds = null): Collection
    {
        $tenant = Tenant::query()->find($tenantId);

        if ($tenant === null) {
            return collect();
        }

        if ($userIds !== null && $userIds !== []) {
            return $tenant->users()->whereIn('users.id', $userIds)->get();
        }

        return $tenant->users()->wherePivotIn('role', self::DefaultRecipientRoles)->get();
    }

    /**
     * @param  list<string>|null  $userIds  null = owners and administrators
     * @return int number of users notified
     */
    public function notify(string $tenantId, string $category, string $title, ?string $body = null, ?string $url = null, ?array $userIds = null): int
    {
        $recipients = $this->recipients($tenantId, $userIds);

        foreach ($recipients as $user) {
            $this->dispatcher->send($user, $category, $title, $body, $url);
        }

        return $recipients->count();
    }
}
